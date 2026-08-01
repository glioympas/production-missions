<?php

namespace Tests\Concerns;

use Closure;
use Illuminate\Support\Facades\DB;

trait AssertsQueries
{
    protected array $capturedQueries = [];

    /**
     * Run a callback and capture every SQL query it triggers.
     */
    protected function captureQueries(Closure $callback): array
    {
        $this->capturedQueries = [];

        // DB::listen fires for every query. We record each one.
        DB::listen(function ($query) {
            $this->capturedQueries[] = [
                'sql' => $query->sql,
                'bindings' => $query->bindings,
                'time' => $query->time,
            ];
        });

        $callback();

        return $this->capturedQueries;
    }

    /**
     * Assert the callback runs FEWER than $max queries.
     */
    protected function assertQueryCountLessThan(int $max, Closure $callback): void
    {
        $queries = $this->captureQueries($callback);
        $count = count($queries);

        $this->assertLessThan(
            $max,
            $count,
            $this->queryFailureMessage("Expected fewer than {$max} queries, got {$count}.", $queries)
        );
    }

    /**
     * Assert the callback runs AT MOST $max queries. (The query budget.)
     */
    protected function assertQueryCountLessThanOrEqual(int $max, Closure $callback): void
    {
        $queries = $this->captureQueries($callback);
        $count = count($queries);

        $this->assertLessThanOrEqual(
            $max,
            $count,
            $this->queryFailureMessage("Expected at most {$max} queries, got {$count}.", $queries)
        );
    }

    /**
     * Assert the callback runs EXACTLY $expected queries.
     */
    protected function assertQueryCount(int $expected, Closure $callback): void
    {
        $queries = $this->captureQueries($callback);
        $count = count($queries);

        $this->assertSame(
            $expected,
            $count,
            $this->queryFailureMessage("Expected exactly {$expected} queries, got {$count}.", $queries)
        );
    }

    /**
     * Build a readable failure message listing every query that ran.
     */
    protected function queryFailureMessage(string $headline, array $queries): string
    {
        $lines = [$headline, '', 'Queries run:'];
        foreach ($queries as $i => $query) {
            $lines[] = '  '.($i + 1).'. '.$query['sql'];
        }

        return implode("\n", $lines);
    }
}
