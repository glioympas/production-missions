<?php

namespace Tests\Feature;

use App\Models\Challenge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BulkCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_all_valid_items(): void
    {
        $response = $this->postJson('/api/challenges/bulk', [
            'items' => [
                ['title' => 'Alpha', 'points' => 100],
                ['title' => 'Bravo', 'points' => 200],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('summary.created', 2);
        $response->assertJsonPath('summary.failed', 0);
        $this->assertDatabaseCount('challenges', 2);
    }

    public function test_it_returns_partial_success_when_some_items_fail(): void
    {
        // Seed a challenge so one batch item hits a duplicate.
        Challenge::factory()->create(['title' => 'Existing']);

        $response = $this->postJson('/api/challenges/bulk', [
            'items' => [
                ['title' => 'New One', 'points' => 100],   // ok
                ['title' => 'Existing', 'points' => 200],  // duplicate -> fails
                ['title' => 'New Two', 'points' => 300],   // ok
            ],
        ]);

        // 207 Multi-Status for partial success.
        $response->assertStatus(207);
        $response->assertJsonPath('summary.created', 2);
        $response->assertJsonPath('summary.failed', 1);
        $response->assertJsonPath('failed.0.index', 1); // duplicate was index 1

        // Proof: the 2 good items saved EVEN THOUGH 1 failed.
        $this->assertDatabaseHas('challenges', ['title' => 'New One']);
        $this->assertDatabaseHas('challenges', ['title' => 'New Two']);
        $this->assertDatabaseCount('challenges', 3); // seeded 1 + 2 new
    }

    public function test_it_rejects_a_malformed_envelope(): void
    {
        // Item missing 'points' -> shape validation fails for that index.
        $this->postJson('/api/challenges/bulk', [
            'items' => [
                ['title' => 'No Points'],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('items.0.points');
    }

    public function test_it_rejects_an_empty_batch(): void
    {
        $this->postJson('/api/challenges/bulk', ['items' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }

    public function test_it_caps_the_batch_size(): void
    {
        $items = [];
        for ($i = 0; $i < 101; $i++) {
            $items[] = ['title' => "T{$i}", 'points' => 10];
        }

        $this->postJson('/api/challenges/bulk', ['items' => $items])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');
    }
}
