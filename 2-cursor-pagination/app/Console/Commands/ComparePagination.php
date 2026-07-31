<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('compare')]
class ComparePagination extends Command
{
    public function handle(): void
    {
        // OFFSET shallow (page 1)
        $start = microtime(true);
        DB::select('SELECT * FROM submissions ORDER BY id DESC LIMIT 20 OFFSET 0');
        $offsetShallow = (microtime(true) - $start) * 1000;

        // OFFSET deep (~page 50,000)
        $start = microtime(true);
        DB::select('SELECT * FROM submissions ORDER BY id DESC LIMIT 20 OFFSET 1000000');
        $offsetDeep = (microtime(true) - $start) * 1000;

        // CURSOR shallow (first page)
        $start = microtime(true);
        DB::select('SELECT * FROM submissions ORDER BY id DESC LIMIT 20');
        $cursorShallow = (microtime(true) - $start) * 1000;

        // CURSOR deep (after a low id — simulates scrolling deep)
        $start = microtime(true);
        DB::select('SELECT * FROM submissions WHERE id < 500000 ORDER BY id DESC LIMIT 20');
        $cursorDeep = (microtime(true) - $start) * 1000;

        $this->table(
            ['Method', 'Shallow (ms)', 'Deep (ms)'],
            [
                ['OFFSET', round($offsetShallow, 2), round($offsetDeep, 2)],
                ['CURSOR', round($cursorShallow, 2), round($cursorDeep, 2)],
            ]
        );

        $this->info("The conclusions are yours");
    }
}
