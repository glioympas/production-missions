<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_charges_only_once_for_repeated_requests_with_same_key(): void
    {
        $user = User::factory()->create([
            'credits' => 0,
        ]);

        $key = Str::uuid();

        $payload = ['credits' => 100];

        // First request
        $this->actingAs($user)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/purchases', $payload)
            ->assertCreated();

        // Second request, SAME key
        $this->actingAs($user)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/purchases', $payload)
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame(100, $user->fresh()->credits);
    }

    public function test_rejects_same_key_with_a_different_body(): void
    {
        $user = User::factory()->create();

        $key = Str::uuid();

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/purchases', ['credits' => 100])
            ->assertCreated();

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/purchases', ['credits' => 999])
            ->assertStatus(422);
    }

    public function test_requires_an_idempotency_key_header(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/purchases', ['credits' => 100])
            ->assertStatus(422);
    }

    public function test_rejects_a_badly_formatted_key(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'abc')   // not a UUID
            ->postJson('/api/purchases', ['credits' => 100])
            ->assertStatus(422);
    }
}
