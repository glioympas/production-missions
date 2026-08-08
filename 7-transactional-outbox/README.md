# Transactional Outbox

Notes + a working Laravel implementation of the transactional outbox pattern. This is the thing that guarantees you never lose an event when you have to "update the database AND tell another system" — without the two ever getting out of sync.

## The problem this solves

Something happens in your app that other systems need to know about. A player submits the correct flag, and now you have to do two things: **save** it to your database (record the solve, add points) and **tell** other systems (update the leaderboard service, send an email, push a notification, emit to Kafka).

The database is one system. The message broker (Redis, Kafka, SQS, RabbitMQ) is another. The naive code writes to the DB, then publishes to the broker:

```php
DB::transaction(function () {
    // save solve, add points
});

$broker->publish('challenge.solved', [...]); // tell everyone else
```

Looks fine. Falls apart in two ways, because those are **two separate systems that can't share a transaction.**

**Events get lost.** The DB transaction commits, then — before the publish runs — the process crashes, the deploy kills it, or the broker is briefly down.

```php
DB::transaction(...);   // committed. Points added.
// crash / deploy / broker down
$broker->publish(...);  // never runs
```

The team has points, but the leaderboard, email, and analytics never hear about it. The event is gone. Silent drift between systems — the bug you find three weeks later when someone asks why the leaderboard disagrees with the database.

**Or phantom events fire.** Flip the order — publish first, then commit — and the opposite happens: other systems act on an event, then your DB rolls back. Now everyone believes something that never happened.

You can't make two systems commit atomically. So you stop trying to.

## How it works

You **can** make two writes atomic — if they're both in the same database.

So instead of writing to the DB and then the broker, you write the event into a plain `outbox` table **in the same transaction** as your business data. Both land or neither does. Then a separate background worker reads unpublished rows and publishes them.

```sql
-- ONE transaction: business write + event write, atomically
INSERT INTO solves (...);
UPDATE teams SET points = points + 100 WHERE id = 42;
INSERT INTO outbox (id, event_type, payload, ...) VALUES (...);
COMMIT;
```

The `outbox` table is a durable buffer living inside your own database, so it inherits your transaction's atomicity. The request never touches the broker at all.

A separate worker (the "relay") then drains it:

```sql
-- worker, running continuously in the background
SELECT * FROM outbox WHERE published_at IS NULL ORDER BY created_at LIMIT 100;
-- publish each to the broker, then:
UPDATE outbox SET published_at = NOW() WHERE id = ...;
```

Think of it as an outbox tray on a desk. Finishing a letter (business write) and dropping a copy in the tray (outbox row) is one motion. A mail carrier (the worker) comes by later and mails whatever's in the tray. Carrier out sick? The letters just wait. Nothing is lost.

## The trade-off

The relay publishes a row, then marks it published. If it crashes *between* those two steps, on restart it publishes that row **again**. This is **at-least-once delivery** — an event can be delivered more than once, never zero times.

That's the right trade: losing an event is a disaster, sending it twice is a minor inconvenience *if consumers are idempotent*. Every event carries a unique `id`, and consumers dedupe on it. Exactly-once delivery is mostly a myth; you approximate it with at-least-once + idempotent consumers.

So the cost of never losing events is: consumers must be able to handle a duplicate. That's it.

## In Laravel

There's no built-in helper — it's a table, a writer, and a worker. The writer just inserts a row inside your existing transaction:

```php
DB::transaction(function () use ($team, $challenge) {
    $team->increment('points', $challenge->points);
    Solve::create([...]);

    Outbox::record('challenge.solved', [   // same transaction
        'team_id'      => $team->id,
        'challenge_id' => $challenge->id,
    ]);
});
// note: we do NOT publish here. The relay does.
```

The relay is an Artisan command running in a loop (like `queue:work`), draining unpublished rows to the broker. In production it runs under Supervisor with several processes.

You never publish inside the request — that's the whole point. The transaction guarantees the row exists; the relay guarantees it eventually ships.

## Two things that make or break it

**1. Multiple workers must not publish the same row.** You want several relay processes for throughput and redundancy. On **MySQL 8+ / Postgres**, wrap the batch select in a transaction with `SELECT ... FOR UPDATE SKIP LOCKED` — each worker locks its batch, others skip locked rows, and work divides itself with zero coordination.

On **MySQL 5.7** (no `SKIP LOCKED`), use an atomic claim instead: each worker stamps a batch with its own `worker_id` via a single `UPDATE ... WHERE locked_by IS NULL LIMIT n` (one UPDATE is atomic, so no two workers claim the same row), then processes only what it stamped.

**2. If you use the claim approach, you MUST have a stale-claim sweeper.** This is the subtle one. With `SKIP LOCKED`, a crashed worker's locks release automatically. With the claim column, they don't — a worker that dies mid-batch leaves rows stamped with its `worker_id` forever, never published.

Fix: a `locked_at` timestamp plus a sweeper that releases any claim older than N minutes, so another worker can pick the rows up:

```php
// reclaim rows from workers that probably died
DB::table('outbox')
    ->whereNotNull('locked_by')
    ->whereNull('published_at')
    ->where('locked_at', '<', now()->subMinutes(5))
    ->update(['locked_by' => null, 'locked_at' => null]);
```

The timeout must be **longer than your worst-case batch time**, or you'll reclaim rows from workers that are merely slow (not dead) and cause avoidable duplicates. This whole class of problem is why upgrading to MySQL 8 (`SKIP LOCKED`) deletes a lot of complexity.

## Fan-out to many consumers

When one event needs to reach several places (Redis, Kafka, email, notifications), the relay doesn't call each one directly. It dispatches a Laravel job per event, and that job fans out to a separate job per consumer — each with its own queue, retries, and scaling. A slow email service then only backs up the `mail` queue; the leaderboard update already happened. Each consumer job must be idempotent on the event `id`, same as before.

## Operating it in production

Three numbers tell you it's healthy, and they're what "how would you run this in prod?" is really asking:

- **Backlog:** count of unpublished rows. Climbing and not falling = broker down or worker dead. Alert on it.
- **Oldest unpublished age:** `now() - MIN(created_at)` over unpublished rows. This is your event latency.
- **Dead-letter count:** rows that failed to publish N times and got parked. Any nonzero = a human should look.

Also: prune published rows on a schedule (they pile up), and run the relay under Supervisor with bounded lifetime (`--max-events` / `--max-time`) so a fresh process replaces it periodically and memory can't leak unbounded.

## Files

- `database/migrations/*_create_ctf_and_outbox_tables.php` — the tables
- `app/Models/Team.php`, `Challenge.php`, `Solve.php`
- `app/Outbox/Broker.php` — interface for the outside world (swappable, testable)
- `app/Outbox/LogBroker.php` — dev implementation that just logs
- `app/Outbox/Outbox.php` — the writer helper (`Outbox::record(...)`)
- `app/Outbox/OutboxRelay.php` — the worker: claim, publish, mark, recover
- `app/Actions/SubmitFlag.php` — the atomic business write
- `app/Console/Commands/OutboxRelayCommand.php` — the looping, self-recycling worker

## Seeing it for yourself

The point is to watch it survive a broker outage without losing anything.

```bash
php artisan migrate --seed
php artisan serve                 # terminal 1
php artisan outbox:relay          # terminal 2 (run a few for multi-worker)
# hit http://127.0.0.1:8000/solve
```

Then break the broker on purpose (make `LogBroker::publish` throw) and hit `/solve` a few times:

- The request still succeeds and points still go up — business writes are unaffected.
- The outbox rows sit with `published_at = null`. **Nothing is lost.**
- "Fix" the broker (stop throwing) and the relay drains the backlog. Every row publishes.

That's the exact scenario the naive code loses data on, and you lose nothing.

## When to use this (and when not to)

Use the outbox when:

- A DB change must reliably trigger something in another system (event, email, webhook, other service).
- You cannot afford to silently lose events.
- You have multiple services or consumers that react to domain events.

This is the production choice for reliable event publishing.

Don't bother when:

- The side effect lives in the same database — just do it in the transaction, no outbox needed.
- Losing the occasional notification genuinely doesn't matter — a plain queued job is simpler.
- There's no second system at all.

## The one-line version

Writing to your database and a message broker in one request is a dual write — they can't commit together, so a crash between them loses events or fires phantom ones. The outbox fixes it by writing the event into a table **in the same transaction** as your data, then a separate worker publishes it — so the event can never be lost. Cost: at-least-once delivery, so consumers must dedupe on the event id.
