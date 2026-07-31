<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('seed-submissions {count=1000000}')]
class SeedSubmissions extends Command
{
    public function handle(): void
    {
        $count = (int) $this->argument('count');
        $batchSize = 5000;
        $inserted = 0;

        $this->info("Inserting {$count} rows...");
        $bar = $this->output->createProgressBar($count);

        $now = now();

        while ($inserted < $count) {
            $rows = [];
            $thisBatch = min($batchSize, $count - $inserted);

            for ($i = 0; $i < $thisBatch; $i++) {
                $rows[] = [
                    'team_name' => 'Team ' . rand(1, 500),
                    'challenge_name' => 'Challenge ' . rand(1, 200),
                    'points' => rand(50, 500),
                    'created_at' => $now,
                    'updated_at' => $now
                ];
            }

            DB::table('submissions')->insert($rows);

            $inserted += $thisBatch;
            $bar->advance($thisBatch);
        }

        $bar->finish();
        $this->newLine();
        $this->info("Done. {$count} rows inserted.");
    }
}
