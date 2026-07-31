# Cursor Pagination

Notes + a working Laravel implementation of cursor pagination. This is the thing that keeps pagination fast when your table gets big, and stops rows from duplicating or getting skipped when data is changing while someone scrolls.

## The problem this solves

You've got a feed or a leaderboard with a lot of rows — say a couple million submissions during a live competition — and the app shows them as infinite scroll. The default way to paginate in Laravel is `paginate()`, which uses `OFFSET` under the hood. That's fine with a hundred rows and falls apart at scale in two ways.

**Deep pages get slow.** "Page 3, 20 per page" becomes:

```sql
SELECT * FROM submissions ORDER BY id DESC LIMIT 20 OFFSET 40;
```

`OFFSET 40` means "find the first 40 rows, throw them away, give me the next 20." The database can't teleport to row 41 — it reads through the first 40 to discard them. Cheap for page 3. But page 50,000:

```sql
SELECT * FROM submissions ORDER BY id DESC LIMIT 20 OFFSET 1000000;
```

Now the DB reads a million rows, throws them all away, and returns 20. A million rows of work to show 20. The deeper you scroll, the slower it gets.

**Rows duplicate or get skipped.** User is on page 1 (newest 20). While they read, 15 new submissions come in. They scroll to page 2 (`OFFSET 20`) — but those first 20 rows got pushed down by the 15 new ones, so page 2 shows rows they already saw. OFFSET counts positions, and positions move when data changes.

## How it works

Instead of "skip N rows," you say "give me rows *after* this specific point." The point is called a **cursor** — usually the id of the last row you saw.

```sql
-- First page: newest 20
SELECT * FROM submissions ORDER BY id DESC LIMIT 20;
-- last row had id = 1,999,981

-- Next page: rows after that one
SELECT * FROM submissions WHERE id < 1999981 ORDER BY id DESC LIMIT 20;
```

No `OFFSET`. Just `WHERE id < 1999981`. The database uses the index on `id` to jump straight to that point and read exactly 20 rows — no reading-and-discarding. Page 50,000 is as fast as page 1.

And the duplicate problem disappears, because you're anchored to a specific row's id, not a position. New rows arriving at the top don't change "everything after id 1999981." You never see duplicates or skips.

## The trade-off

Cursor pagination only does **next / previous**, not "jump to page 47." There are no page numbers and no total count. That's fine for feeds and infinite scroll — nobody jumps to page 47 of an activity feed. If you genuinely need "go to page N" (like search results where people jump around), OFFSET is still the right tool. For feeds, cursor wins.

## In Laravel

It's built in. Use `cursorPaginate()` instead of `paginate()`:

```php
Submission::orderBy('id', 'desc')->cursorPaginate(20);
```

The response gives you the data plus a `next_cursor` (the last row's id, encoded) and a `next_page_url`. Pass the cursor back to get the next page. Laravel decodes it into `WHERE id < ...` for you.

You never write the SQL, but it's worth knowing what it's doing underneath.

## Two things that make or break it

**1. The ordered column must be indexed.** The whole speed win comes from the DB jumping to the cursor point using an index. We order by `id`, which is the primary key and already indexed. If you paginate by `created_at`, put an index on `created_at` or you lose the benefit.

**2. End the ordering with a unique column (tie-breaker).** This is the subtle one. Ordering by `id` alone is safe because ids are unique — "after id 1999981" points to exactly one row. But if you order by something non-unique like `points` or `created_at`, a cursor on it alone is ambiguous.

Example: three teams all have 500 points. Page 1 shows two of them, cursor is "after 500 points," page 2 runs `WHERE points < 500` — and the third 500-point team gets skipped, because they're not `< 500`. Rows silently vanish at the page boundary.

Fix: add `id` as a tie-breaker so the order is fully determined:

```php
Submission::orderBy('points', 'desc')
    ->orderBy('id', 'desc')   // unique tie-breaker
    ->cursorPaginate(20);
```

Now the cursor is "after (points=500, id=8)", which points to exactly one row, and the leftover 500-point team gets picked up correctly. Rule of thumb: whatever you cursor-paginate by, end the ordering with a unique column.

## Files

- `app/Http/Controllers/FeedController.php` — the endpoint
- `app/Models/Submission.php`
- `database/migrations/*_create_submissions_table.php`
- `database/factories/SubmissionFactory.php`
- `app/Console/Commands/SeedSubmissions.php` — seeds a million rows fast (batched raw inserts)
- `app/Console/Commands/ComparePagination.php` — times offset vs cursor so you can see it
- `tests/Feature/CursorPaginationTest.php`

## Seeing it for yourself

The point of this mission is to actually watch OFFSET fall over, not just read about it.

```bash
php artisan migrate
php artisan seed:submissions 1000000
php artisan compare
```

You'll get something like:

```
+--------+--------------+-----------+
| Method | Shallow (ms) | Deep (ms) |
+--------+--------------+-----------+
| OFFSET | 0.8          | 850.2     |
| CURSOR | 0.7          | 0.9       |
+--------+--------------+-----------+
```

Don't fixate on the exact numbers — they depend on your DB, your machine, and caching. The shape is the lesson: OFFSET deep explodes, CURSOR stays flat.

Use MySQL or Postgres for this, not SQLite, if you want realistic timings.

To see *why*, run EXPLAIN on both:

```sql
EXPLAIN SELECT * FROM submissions ORDER BY id DESC LIMIT 20 OFFSET 1000000;
-- look at the "rows" column: ~1,000,020. It examines a million rows to serve 20.

EXPLAIN SELECT * FROM submissions WHERE id < 500 ORDER BY id DESC LIMIT 20;
-- "rows" is tiny, and it's using the index. Straight to the point.
```

## simplePaginate vs cursorPaginate (a common mix-up)

`simplePaginate()` looks similar but does NOT solve this problem. It just drops the `COUNT(*)` that regular `paginate()` runs for page numbers — it still uses OFFSET, so it still has the deep-page slowness and still duplicates rows on shifting data. It's a small optimization, not the fix.

`cursorPaginate()` is a different mechanism entirely: no OFFSET, fast at any depth, no duplicates. If someone asks "how do I speed up pagination on a 5-million-row feed," the answer is cursorPaginate, not simplePaginate — and knowing the difference (simple still uses OFFSET) is the point.

## When to use this (and when not to)

Use cursor pagination when:

- Infinite scroll or feeds (activity feed, leaderboard).
- Large tables where users can scroll deep.
- Live-updating data where you must avoid duplicates/skips.

This is the production choice for feeds

Don't bother when:

- You need page numbers or "page X of Y" (admin tables, search where people jump to arbitrary pages) — regular `paginate()`.
- The table is small and won't grow — `paginate()` is fine, the difference doesn't matter.

## The one-line version

OFFSET says "skip N rows" and pays for it by reading and throwing them away; cursor pagination says "give me rows after this indexed point" and jumps straight there — so it stays fast at any depth and doesn't duplicate rows when data shifts. Cost: next/previous only, and you need an indexed ordered column with a unique tie-breaker.
