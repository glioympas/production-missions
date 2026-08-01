<?php

namespace Tests\Feature;

use App\Models\Solve;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsQueries;
use Tests\TestCase;

class LeaderboardQueryBudgetTest extends TestCase
{
    use AssertsQueries, RefreshDatabase;

    public function test_leaderboard_stays_within_query_budget(): void
    {
        Team::factory()->count(50)->create()->each(function (Team $team) {
            Solve::factory()->count(5)->create(['team_id' => $team->id]);
        });

        // The budget: at most 5 queries, no matter how many teams.
        $this->assertQueryCountLessThanOrEqual(5, function () {
            $this->getJson('/api/leaderboard')->assertOk();
        });
    }
}
