# Bulk / Batch Endpoints

Notes + a working Laravel bulk-create endpoint that takes N items and returns per-item success/failure instead of all-or-nothing. The real lesson here is about **database transactions**, where you put the transaction boundary is the whole design.

## The problem this solves

Someone needs to import 100 challenges at once (from a spreadsheet, say). Making them call `POST /challenges` 100 times is chatty and slow — 100 network round-trips, 100 auth checks, and if the connection drops halfway they don't know which ones saved. A bulk endpoint takes all 100 in one request and pays that overhead once.

But then the real question: what happens when item 37 has a duplicate title, or item 52 has bad data? That's where transactions come in.

## The core decision: where does the transaction go?

This is the entire mission. A bulk endpoint is really a question about transaction boundaries.

### Option A — all-or-nothing (one transaction for the whole batch)

Wrap all 100 inserts in a single `DB::transaction()`. If any one fails, the whole thing rolls back — nothing is saved.

```
BEGIN
  insert item 1  ok
  insert item 2  ok
  ...
  insert item 37 FAILS (duplicate)
ROLLBACK   <- items 1-36 are undone too. Zero saved.
```

### Option B — partial success (one transaction per item)

Each item gets its own transaction. Item 37 failing doesn't touch the others. You collect per-item outcomes and report them all back.

```
insert item 1  ok  (committed)
insert item 2  ok  (committed)
...
insert item 37 FAILS (this item rolls back, recorded as failed)
insert item 38 ok  (committed)
...
-> 99 created, 1 failed
```

### How to choose (this is the deliberate part)

The rule: **the transaction boundary matches the unit of independence.**

- **Items are interdependent** -> all-or-nothing. They only make sense together, so a partial result is garbage. Classic example: a money transfer (debit one account, credit another) — you never want the debit to commit while the credit fails. Or an order plus its line items — a half-created order is broken data. Whole batch = one unit = one transaction.

- **Items are independent** -> partial success. Each stands alone. Importing 100 unrelated challenges: challenge 37 being a duplicate has nothing to do with challenge 38. Saving 99 and reporting 1 failure is genuinely more useful than saving 0. Each item = one unit = one transaction per item.

For a challenge import the items are independent, so this mission builds partial success.

### The subtle transaction detail

For partial success you might think "just don't use a transaction at all." But you often still need a transaction **per item**, because creating one item might involve **several writes that must be atomic together**.

Example: creating a challenge inserts the challenge row AND a flag row AND a scoring config. If the flag insert fails, you don't want a half-created challenge with no flag. So each item is wrapped in its own transaction — atomic *within* the item, isolated *between* items:

```
Item 37: BEGIN -> insert challenge -> insert flag FAILS -> ROLLBACK (just this item)
Item 38: BEGIN -> insert challenge -> insert flag ok    -> COMMIT
```

So it's not "transaction vs no transaction." It's "one big transaction (all-or-nothing) vs many small transactions (partial success)." The boundary is the decision.

## How it's built

The processing loop is the whole thing — each item in its own transaction, its own try/catch:

```php
foreach ($items as $index => $data) {
    try {
        // Each item's own transaction: atomic within the item,
        // committed before the next item starts.
        $challenge = DB::transaction(function () use ($data) {
            return Challenge::create([
                'title'  => $data['title'],
                'points' => $data['points'],
            ]);
        });

        $created[] = ['index' => $index, 'id' => $challenge->id];
    } catch (Throwable $e) {
        // This item failed (e.g. duplicate). Record it and keep going.
        $failed[] = ['index' => $index, 'error' => $this->messageFor($e)];
    }
}
```

Three things make it partial-success:

1. **Per-item `try/catch`** — a throw in one iteration is caught and recorded; the loop continues.
2. **`DB::transaction()` per item** — each item's writes are atomic together, and commit before the next item runs, so committed items survive later failures.
3. **Separate `created` / `failed` lists, keyed by `index`** — the client sent items in an order, so telling them "index 37 failed, here's why" lets them map the failure back to their input. "1 item failed" with no index is useless.

If you swapped this for one `DB::transaction()` around the whole `foreach`, you'd have all-or-nothing — one bad item rolls back everything. That single change IS the difference between the two designs.

## Two kinds of validation (keep them separate)

There are two levels of "is this item OK", and separating them keeps the code clean:

1. **Shape / envelope validation** — is `title` present, is `points` an integer >= 0? Cheap, done upfront by a Form Request, reported per-index (`items.3.points`). Bad shape is rejected before touching the database.

2. **Business / runtime failures** — the title is a *duplicate*, discovered only when the database rejects the insert. These become the per-item `failed` entries during processing.

Validate shape upfront; catch business failures per-item.

## Status codes (commonly gotten wrong)

- **201 Created** — everything succeeded.
- **207 Multi-Status** — partial success: some created, some failed. This is the correct code — it tells the client "look inside for per-item results" instead of pretending everything's fine (200) or everything failed (4xx). Returning 207 is a signal you actually understand batch APIs.
- **422** — the envelope was malformed (empty batch, bad shape) — rejected before processing.

## Always cap the batch size

The Form Request caps `items` at `max:100`. Without a cap, someone sends a million items and blows up your memory or times out. Pick a sensible ceiling per endpoint.

## Files

- `app/Actions/BulkCreateChallenges.php` — the per-item transaction loop (the core)
- `app/Http/Requests/BulkStoreChallengeRequest.php` — envelope + per-item shape validation
- `app/Http/Controllers/BulkChallengeController.php` — picks 201/207, returns summary + results
- `app/Models/Challenge.php`
- `database/migrations/*_create_challenges_table.php` (title is unique, to demo duplicate failures)
- `database/factories/ChallengeFactory.php`
- `tests/Feature/BulkCreateTest.php`

## Testing

The key test seeds an existing challenge, then submits a batch of three where the middle one duplicates it. It asserts 207, two created, one failed at index 1 — and checks the database has the two good ones. That proves partial success: the good items saved even though one failed in the middle.

To feel the difference, wrap the whole loop in a single `DB::transaction()` and rerun — that test fails, because now all three roll back and zero are saved. That failure is exactly the all-or-nothing vs partial-success distinction.

## The one-line version

A bulk endpoint creates N items in one request instead of N chatty round-trips, and the whole design is a transaction-boundary decision: one transaction for the whole batch (all-or-nothing, for interdependent items where a partial result is garbage) vs one transaction per item (partial success, for independent items — save the good ones, report failures by index). The boundary matches the unit of independence. Return 207 for partial success, cap the batch size, validate shape upfront, and catch business failures per item.
