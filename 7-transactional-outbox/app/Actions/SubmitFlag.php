<?php

namespace App\Actions;

use App\Models\Challenge;
use App\Models\Solve;
use App\Models\Team;
use App\Outbox\Outbox;
use Illuminate\Support\Facades\DB;

class SubmitFlag
{
    public function handle(Team $team, Challenge $challenge): void
    {
        DB::transaction(function () use ($team, $challenge) {
            $team->increment('points', $challenge->points);

            Solve::create([
                'team_id'      => $team->id,
                'challenge_id' => $challenge->id,
            ]);

            // --- event write, SAME transaction ---
            Outbox::record('challenge.solved', [
                'team_id'      => $team->id,
                'challenge_id' => $challenge->id,
                'points'       => $challenge->points,
                'solved_at'    => now()->toIso8601String(),
            ]);
        });

        // The transaction guarantees the outbox row exists.
        // The relay worker publishes it. That's the whole point.
    }
}
