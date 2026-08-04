<?php

namespace Tests\Feature;

use App\Exceptions\StaleModelException;
use App\Models\Challenge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OptimisticLockingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_and_increments_the_version(): void
    {
        $challenge = Challenge::factory()->create(['points' => 500, 'version' => 1]);

        $challenge->updateWithVersion(['points' => 300], expectedVersion: 1);

        $this->assertSame(300, $challenge->points);
        $this->assertSame(2, $challenge->version);
    }

    public function test_it_rejects_a_stale_update(): void
    {
        $challenge = Challenge::factory()->create(['points' => 500, 'version' => 1]);

        $organizerA = Challenge::find($challenge->id); // version 1
        $organizerB = Challenge::find($challenge->id); // version 1

        $organizerA->updateWithVersion(['points' => 300], expectedVersion: 1); // now version 2

        $this->expectException(StaleModelException::class);

        $organizerB->updateWithVersion(['points' => 500], expectedVersion: 1); // stale -> throws
    }

    public function test_it_prevents_the_lost_update(): void
    {
        $challenge = Challenge::factory()->create(['points' => 500, 'version' => 1]);

        $organizerA = Challenge::find($challenge->id);
        $organizerB = Challenge::find($challenge->id);

        $organizerA->updateWithVersion(['points' => 300], expectedVersion: 1);

        try {
            $organizerB->updateWithVersion(['points' => 500], expectedVersion: 1);
        } catch (StaleModelException) {
        }

        $this->assertSame(300, $challenge->fresh()->points); // NOT clobbered to 500
    }

    public function test_the_endpoint_returns_409_on_conflict(): void
    {
        $challenge = Challenge::factory()->create(['points' => 500, 'version' => 1]);

        // Bump it to version 2 first.
        $challenge->updateWithVersion(['points' => 300], expectedVersion: 1);

        // Client saves with the stale version 1.
        $this->putJson("/api/challenges/{$challenge->id}", [
            'title' => 'Updated',
            'description' => 'Updated',
            'points' => 999,
            'version' => 1,
        ])->assertStatus(409);

        $this->assertSame(300, $challenge->fresh()->points); // stale 999 not written
    }
}
