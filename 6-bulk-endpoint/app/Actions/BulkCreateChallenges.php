<?php

namespace App\Actions;

use App\Models\Challenge;
use Illuminate\Support\Facades\DB;
use Throwable;

class BulkCreateChallenges
{
    /**
     * Create many challenges, each independently. One item failing does NOT
     * roll back the others (partial success).
     *
     * @param  array<int, array{title: string, points: int}>  $items
     * @return array{created: array<int, mixed>, failed: array<int, mixed>}
     */
    public function handle(array $items): array
    {
        $created = [];
        $failed = [];

        foreach ($items as $index => $data) {
            try {
                // Each item gets its OWN transaction. If an item's work spans
                // multiple writes, they're atomic together — but isolated from
                // the other items in the batch.
                $challenge = DB::transaction(function () use ($data) {
                    return Challenge::query()->create([
                        'title' => $data['title'],
                        'points' => $data['points'],
                    ]);
                });

                $created[] = [
                    'index' => $index,
                    'id' => $challenge->id,
                    'title' => $challenge->title,
                ];
            } catch (Throwable $e) {
                // This item failed (e.g. duplicate title). Record it, move on.
                $failed[] = [
                    'index' => $index,
                    'error' => $this->messageFor($e),
                ];
            }
        }

        return ['created' => $created, 'failed' => $failed];
    }

    private function messageFor(Throwable $e): string
    {
        // Turn a duplicate-key violation into a friendly message.
        if (str_contains($e->getMessage(), 'Duplicate') ||
            str_contains($e->getMessage(), 'UNIQUE')) {
            return 'A challenge with this title already exists.';
        }

        return 'Could not create this challenge.';
    }
}
