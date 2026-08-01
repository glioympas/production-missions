# N+1 Queries + Query Budget Testing

Notes + a working Laravel example of the N+1 query problem, how to fix it, and — the part I actually wanted to learn — a reusable way to **test how many queries an endpoint runs** so the bug can't sneak back in.
We catch it on the CI for example, before Production.

## The problem this solves

You have an endpoint that loads a list and shows something related for each item. A leaderboard: top 50 teams, each with its country and how many challenges it solved. Works fine in dev with 5 teams. Then in a real competition with 50 teams and actual traffic, the database melts, because this one endpoint is firing ~100 queries per request.

That's N+1. It's the most common performance bug in Laravel (and every ORM), and the reason it's dangerous is that it hides in development and only shows up under real data.

## What N+1 actually is

Say you load 50 teams and then read each team's country in a loop:

```php
$teams = Team::take(50)->get();      // 1 query
foreach ($teams as $team) {
    echo $team->country->name;        // 1 query EACH time → 50 queries
}
```

Count them: 1 query for the teams, then N more (one per team) for the countries. That's "1 + N" — the N+1. With 50 teams it's 51 queries.

The thing that makes it a real problem: **it grows with your data.** 5 teams = 6 queries (you'll never notice). 500 teams = 501 queries (your DB is on fire). The bug scales against you, which is exactly why it slips through code review and local testing.

### Why it happens

Eloquent relationships are lazy by default. When you write `$team->country`, Eloquent goes "oh, you want the country now? let me fetch it" and runs a query right then. Do that inside a loop and you get a query per iteration. It's being helpful by only loading what you ask for — but in a loop that helpfulness turns into death by a thousand queries.

## The fix: eager loading

Tell Eloquent up front to load the related data in one batch:

```php
$teams = Team::query()
    ->with('country')       // loads ALL countries in one query (WHERE id IN (...))
    ->withCount('solves')   // adds a solves_count computed in the main query
    ->take(50)
    ->get();
```

Now it's ~2 queries total instead of ~100:

- `with('country')` — grabs every country in a single batched query, so `$team->country->name` uses already-loaded data with no extra query.
- `withCount('solves')` — adds a `solves_count` to each team, computed by the database as part of the main query. Use this when you only need the *number*; use `with()` when you need the actual related rows.

51 → 2. Same data.

## The part I wanted to learn: testing query counts

Fixing it once isn't enough. Six months later someone adds another relationship access in the loop and the N+1 is back, and nobody notices until prod is slow again.

So instead of trusting everyone to remember, you write a test that **counts how many queries an endpoint runs and fails if it's too many.** This is called a query budget — "this endpoint is allowed at most 5 queries, period." If a change pushes it over, CI goes red before it ships.

### How it works under the hood

Laravel gives you `DB::listen()`, which fires a callback for every single query that runs. So you can wrap a piece of code, count the queries it triggers, and assert on that number:

```php
$count = 0;
DB::listen(function () use (&$count) {
    $count++;
});

$this->getJson('/api/leaderboard');

$this->assertLessThanOrEqual(5, $count);
```

That's the whole idea. Everything else is just making it reusable and giving nice failure messages.

### The reusable trait

Rather than repeat that in every test, I put it in a trait (`tests/Concerns/AssertsQueries.php`) and `use` it wherever I need it:

```php
class LeaderboardTest extends TestCase
{
    use RefreshDatabase, AssertsQueries;

    public function test_leaderboard_stays_within_query_budget(): void
    {
        Team::factory()->count(50)->create()->each(function (Team $team) {
            Solve::factory()->count(5)->create(['team_id' => $team->id]);
        });

        // The budget: at most 5 queries, no matter how many teams exist.
        $this->assertQueryCountLessThanOrEqual(5, function () {
            $this->getJson('/api/leaderboard')->assertOk();
        });
    }
}
```

The trait gives me three assertions, all built on the same `captureQueries()` helper:

- `assertQueryCountLessThanOrEqual($max, $callback)` — **the budget.** At most N queries. This is the one I use most.
- `assertQueryCountLessThan($max, $callback)` — strictly fewer than N.
- `assertQueryCount($exact, $callback)` — exactly N, when you want to pin it down precisely.

Each one runs the callback, counts the queries, and if it fails, prints **every query that ran** so you can see exactly what blew the budget instead of just getting a number.

### Why count-based is enough

There are fancier ways to detect N+1 (fingerprinting queries, checking if the count grows with data size, Laravel's `preventLazyLoading`). They're nice, but honestly a plain count catches the bug in practice: if an endpoint that should run 2 queries suddenly runs 52, the count test fails and tells you immediately. A bloated count IS the N+1 showing its face. I kept it simple on purpose — one number, one budget, done.

### Seeing it work

Point the test at the buggy controller (lazy loading in a loop) and it fails:

```
Expected at most 5 queries, got 101.

Queries run:
  1. select * from "teams" limit 50
  2. select * from "countries" where "id" = ? limit 1
  3. select * from "countries" where "id" = ? limit 1
  ... (and so on, 101 lines)
```

That wall of near-identical queries in the failure output is the N+1, right there in front of you. Fix the controller with eager loading, re-run, and it passes with ~2 queries. That flip — red on the bug, green on the fix — is the whole point.

## The trait

```php
<?php

namespace Tests\Concerns;

use Closure;
use Illuminate\Support\Facades\DB;

trait AssertsQueries
{
    protected array $capturedQueries = [];

    protected function captureQueries(Closure $callback): array
    {
        $this->capturedQueries = [];

        DB::listen(function ($query) {
            $this->capturedQueries[] = [
                'sql'      => $query->sql,
                'bindings' => $query->bindings,
                'time'     => $query->time,
            ];
        });

        $callback();

        return $this->capturedQueries;
    }

    protected function assertQueryCountLessThan(int $max, Closure $callback): void
    {
        $queries = $this->captureQueries($callback);
        $count = count($queries);
        $this->assertLessThan($max, $count,
            $this->queryFailureMessage("Expected fewer than {$max} queries, got {$count}.", $queries));
    }

    protected function assertQueryCountLessThanOrEqual(int $max, Closure $callback): void
    {
        $queries = $this->captureQueries($callback);
        $count = count($queries);
        $this->assertLessThanOrEqual($max, $count,
            $this->queryFailureMessage("Expected at most {$max} queries, got {$count}.", $queries));
    }

    protected function assertQueryCount(int $expected, Closure $callback): void
    {
        $queries = $this->captureQueries($callback);
        $count = count($queries);
        $this->assertSame($expected, $count,
            $this->queryFailureMessage("Expected exactly {$expected} queries, got {$count}.", $queries));
    }

    protected function queryFailureMessage(string $headline, array $queries): string
    {
        $lines = [$headline, '', 'Queries run:'];
        foreach ($queries as $i => $query) {
            $lines[] = '  '.($i + 1).'. '.$query['sql'];
        }
        return implode("\n", $lines);
    }
}
```

## Tools for spotting N+1 while developing

The test catches it in CI, but for finding it in the first place:

- **Laravel Debugbar** (`barryvdh/laravel-debugbar`, dev only) — shows a query count and list per page. Quickest way to eyeball it in the browser.
- **Laravel Telescope** — the bigger, production-grade version; `/telescope/queries` shows counts per request.

## Files

- `app/Http/Controllers/LeaderboardController.php` — the endpoint (buggy + fixed versions)
- `app/Models/{Team,Country,Solve}.php`
- `database/migrations/*` and `database/factories/*`
- `tests/Concerns/AssertsQueries.php` — the reusable query-count trait
- `tests/Feature/LeaderboardQueryBudgetTest.php`

## The one-line version

N+1 is when loading N items triggers N extra queries because you touch a lazy relationship in a loop — it hides in dev and kills prod because the count scales with data. Fix it with eager loading (`with`, `withCount`), and lock it with a query-budget test (`assertQueryCountLessThanOrEqual`) so nobody can silently bring it back. The count is the signal: if an endpoint that should run 2 queries runs 52, the test fails and shows you every query.
