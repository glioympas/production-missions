# Idempotency Keys

Notes + a working Laravel implementation of idempotency keys. This is the thing that stops a client from accidentally doing the same write twice (double charge, double order, double credits) when it retries a request.

## The problem this solves

A client sends a request that changes something important — say, charging a card. The server does the work fine, but the response gets lost on the way back (dropped wifi, timeout, whatever). The client is now stuck: it has no idea if the charge went through. "Server did the work but the reply vanished" looks *exactly* the same as "the request never arrived."

So the client does the sensible thing and retries. And now the card gets charged twice.

The annoying part is you can't fix this by telling the client "don't retry." Retrying is correct — otherwise a lost response would mean the purchase silently disappears. The real issue is deeper:

> The server can't tell the difference between "a retry of something I already did" and "a brand new request."

Two identical charge requests look the same whether it's one purchase retried or two real purchases. Only the *client* knows "this is the same attempt as before." Idempotency keys are how the client tells the server that.

## How it works

The client generates a unique ID (a UUID) for each action and sends it in a header:

```
POST /purchases
Idempotency-Key: 4a7b9c12-...
{ "credits": 100 }
```

Server rules:

- **First time it sees a key** → do the work, save the response, return it.
- **Sees the same key again** → don't do the work, just replay the saved response.

If the client retries, it sends the *same* key. So the server recognizes it and replays instead of re-charging. The client can retry 100 times and the card is still charged once.

One extra rule: **same key + different body = reject with 422.** A key is supposed to identify one specific request. If the body changed, something is wrong (client bug or someone messing around), so we don't touch it.

## The part that actually matters: the race condition

This is where naive implementations break. Picture two identical requests arriving at basically the same millisecond (double click, or the app auto-retrying fast). Both do "check if key exists" at the same time:

```
Request A: is key "abc" there? → no
Request B: is key "abc" there? → no   (A hasn't saved it yet)
Request A: charge card
Request B: charge card                 ← double charge, again
```

"Check if it exists" and "then create it" are two separate steps, and the second request slips in between them. So the key check by itself does NOT save you.

The fix is a **lock**. Only one request at a time is allowed to process a given key. The second one waits, and by the time it gets in, the key already exists, so it replays instead of charging.

We use Redis (`Cache::lock`) for this. Redis runs commands one at a time, so the "set the lock only if it doesn't exist" step (`SET NX`) is atomic — only one request can ever win it. The loser waits, then replays.

There's also a `unique` constraint on the `key` column in the database as a backup. If the lock ever fails for some weird reason (Redis restart, lock expired mid-work), the DB still refuses a duplicate key. Belt and suspenders. In practice the lock does the work and the constraint is the safety net of last resort.

## Files

- `app/Http/Middleware/EnsureIdempotency.php` — all the logic lives here
- `app/Models/IdempotencyKey.php` — stores key, request hash, saved response
- `database/migrations/*_create_idempotency_keys_table.php`
- `app/Http/Controllers/PurchaseController.php` — example endpoint (buy credits)
- `tests/Feature/IdempotencyTest.php`

## The flow in the middleware

1. Read the `Idempotency-Key` header. No key → 400.
2. Validate it's a UUID → otherwise 400. (Stops garbage/short keys that could collide.)
3. Hash the request body (to detect "same key, different body" later).
4. Grab a lock on the key. If we can't get it within a few seconds → 409 ("still processing").
5. Look up the key:
   - Exists + different body → 422.
   - Exists + response already saved → replay it (with an `Idempotent-Replayed: true` header).
   - Doesn't exist → create the record now (reserve the key), then run the real controller.
6. Save the response so future retries can replay it. (Skip saving 5xx — those should be retryable, not replayed as a stuck error.)
7. Release the lock. Always, even on exception (`finally`).

## Cleanup

Every request creates a row. That table would grow forever, and since the `key` column is unique+indexed and we hit it on every request, we want it small and fast. Keys are only useful during the retry window (seconds to minutes in reality), so anything older than 24h is dead weight.

A daily scheduled job deletes keys older than a day:

```php
Schedule::call(function () {
    IdempotencyKey::where('created_at', '<', now()->subDay())->delete();
})->daily();
```

Reminder: the scheduler only runs if you have the cron entry set up on the server:

```
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

## Client side (don't skip this)

The whole thing only works if the client does its part:

- Generate **one key per action**, not per request.
- **Reuse the same key on every retry** of that action. This is the entire point — a new key on retry defeats it.
- Generate a **new key** only for a genuinely new action.

Common mistake: generating the key inside the retry loop, so each retry gets a new key. Generate it *once*, outside the retry, then retry with it.

Also: only retry on the errors worth retrying — network failures, timeouts, and 5xx. Don't retry a 422 (it'll just fail again the same way). The one 4xx you *do* retry is 409 ("still processing"), because it means your other attempt is mid-flight.

The disabled-button trick on the frontend helps with casual double-clicks, but it's UX polish, not real protection — it does nothing for the lost-response retry case. The key + server logic is the actual guarantee.

## When to use this (and when not to)

Use idempotency keys when **all three** are true:

1. The request changes data (not a read).
2. The client might retry it (networks fail, apps auto-retry, users double-click).
3. Doing it twice causes real harm.

Classic cases: payments, awarding points/credits, sending emails/notifications, placing orders, provisioning resources (spinning up a container/VM), one-time creates.

Don't bother when:

- It's a **read** (GET) — reads are already safe to repeat.
- There's a **natural unique constraint** that fits ("one registration per team per event") — just use a unique index, simpler and DB-enforced.
- The update is **naturally idempotent** — setting an absolute value (`name = "X"`, `score = 500`) is fine to repeat. It's *incrementing* (`score + 10`) that's dangerous.

## Webhooks are the same idea, slightly different

Incoming webhooks (Stripe, Recurly, etc.) get resent by the sender on purpose — that's normal at-least-once delivery, not an edge case. So you dedupe them the same way, except **the key comes from the sender**, not you: use their event ID (e.g. Stripe's `evt_...`) as the idempotency key.

For webhooks you usually don't even need the Redis lock — a `unique` constraint on `event_id` plus doing the insert + work in one transaction handles concurrent duplicates (the DB rejects the second insert). Return 2xx on a duplicate so the sender stops retrying. And this is separate from **signature verification**, which you also need — idempotency stops double-processing, signatures stop fakes. Two different problems.

## Prior art

This is basically how Stripe and AWS do it, worth reading:

- Stripe uses an `Idempotency-Key` header on POST/DELETE (charges, customers, etc.). Saves status + body of the first request, replays on repeat, 24h window, suggests UUIDs, rejects same-key-different-params.
- AWS does the same thing but calls it a `ClientToken`, mostly to avoid launching duplicate infrastructure (EC2 `RunInstances`, ECS `RunTask`). Same pattern, different name.

## Alternatives (so you know the landscape)

- **Unique constraint** — when there's a natural "only one can exist" rule. Simplest. Reach for this first when it fits.
- **Optimistic / pessimistic locking** — for protecting edits to one existing row (the lost-update problem), not for preventing double-creates. Different tool.
- **Message dedup** — the queue/async version of the same idea (dedupe by message ID).
- **Throttling** — completely different problem. Limits *how many* requests, doesn't understand *duplicates*. Not a substitute.

The mental shortcut for all of it: **"if the client retried this, would I regret doing it twice?"** If yes → idempotency key.
