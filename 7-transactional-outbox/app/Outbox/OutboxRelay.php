<?php
// app/Outbox/OutboxRelay.php
namespace App\Outbox;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

readonly class OutboxRelay
{
    /**
     * How long a claim may sit before we assume the worker died and reclaim it.
     * Must be comfortably LONGER than your worst-case batch processing time,
     * so we never steal rows from a worker that's merely slow.
     */
    private const int STALE_CLAIM_SECONDS = 300; // 5 minutes

    private const int MAX_ATTEMPTS = 5;

    public function __construct(
        private Broker $broker,
        private string $workerId,   // unique per running worker process
    ) {}

    /**
     * One full cycle: recover stale claims, claim a batch, publish it.
     * Returns number of events published this cycle.
     */
    public function processBatch(int $limit = 100): int
    {
        $this->recoverStaleClaims();

        $claimed = $this->claimBatch($limit);
        if ($claimed === 0) {
            return 0;
        }

        return $this->publishClaimed();
    }

    /**
     * STEP 1 — Reclaim rows whose owning worker likely crashed.
     * A single atomic UPDATE. Safe to run from every worker.
     */
    private function recoverStaleClaims(): void
    {
        DB::table('outbox')
            ->whereNotNull('locked_by')
            ->whereNull('published_at')
            ->whereNull('failed_at')
            ->where('locked_at', '<', now()->subSeconds(self::STALE_CLAIM_SECONDS))
            ->update([
                'locked_by' => null,
                'locked_at' => null,
            ]);
    }

    /**
     * STEP 2 — Atomically claim up to $limit unclaimed rows for THIS worker.
     * This single UPDATE is the synchronization point. Two workers running it
     * at the same instant are serialized by MySQL: each row is stamped by
     * exactly one worker. No blocking, no overlap. This is our SKIP LOCKED.
     *
     * Returns how many rows this worker claimed.
     */
    private function claimBatch(int $limit): int
    {
        // MySQL 5.7 supports UPDATE ... ORDER BY ... LIMIT.
        return DB::update("
            UPDATE outbox
            SET locked_by = ?, locked_at = ?
            WHERE published_at IS NULL
              AND failed_at   IS NULL
              AND locked_by   IS NULL
            ORDER BY created_at
            LIMIT {$limit}
        ", [$this->workerId, now()]);
    }

    /**
     * STEP 3 — Process only the rows THIS worker owns.
     */
    private function publishClaimed(): int
    {
        $rows = DB::table('outbox')
            ->where('locked_by', $this->workerId)
            ->whereNull('published_at')
            ->whereNull('failed_at')
            ->orderBy('created_at')
            ->get();

        $processed = 0;

        foreach ($rows as $row) {
            try {
                $this->broker->publish($row->event_type, [
                    'id'      => $row->id,
                    'type'    => $row->event_type,
                    'payload' => json_decode($row->payload, true),
                ]);

                // Success: mark published and release the claim.
                DB::table('outbox')->where('id', $row->id)->update([
                    'published_at' => now(),
                    'locked_by'    => null,
                    'locked_at'    => null,
                ]);

                $processed++;
            } catch (\Throwable $e) {
                $attempts = $row->attempts + 1;

                // Failure: bump attempts, release the claim so it retries,
                // dead-letter after MAX_ATTEMPTS so it stops blocking.
                DB::table('outbox')->where('id', $row->id)->update([
                    'attempts'  => $attempts,
                    'locked_by' => null,
                    'locked_at' => null,
                    'failed_at' => $attempts >= self::MAX_ATTEMPTS ? now() : null,
                ]);

                Log::warning('Outbox publish failed', [
                    'id'       => $row->id,
                    'attempts' => $attempts,
                    'worker'   => $this->workerId,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return $processed;
    }
}
