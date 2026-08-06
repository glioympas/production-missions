<?php

namespace Tests\Feature;

use App\Models\Challenge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiVersioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_v1_returns_the_flat_points_shape(): void
    {
        Challenge::factory()->create([
            'title' => 'SQLi',
            'base_points' => 500,
            'first_blood_bonus' => 100,
        ]);

        $this->getJson('/api/v1/challenges')
            ->assertOk()
            ->assertJsonStructure([['id', 'title', 'points']])
            ->assertJsonPath('0.points', 500)
            ->assertJsonMissingPath('0.scoring'); // v1 must NOT leak v2 shape
    }

    public function test_v2_returns_the_scoring_object_shape(): void
    {
        Challenge::factory()->create([
            'title' => 'SQLi',
            'base_points' => 500,
            'first_blood_bonus' => 100,
        ]);

        $this->getJson('/api/v2/challenges')
            ->assertOk()
            ->assertJsonStructure([['id', 'title', 'scoring' => ['base', 'first_blood_bonus']]])
            ->assertJsonPath('0.scoring.base', 500)
            ->assertJsonPath('0.scoring.first_blood_bonus', 100)
            ->assertJsonMissingPath('0.points'); // v2 must NOT keep old shape
    }

    public function test_v1_sends_deprecation_headers(): void
    {
        Challenge::factory()->create();

        $response = $this->getJson('/api/v1/challenges')->assertOk();

        $response->assertHeader('Sunset');
        $response->assertHeader('Warning');
        $response->assertHeader('Link');
    }

    public function test_v2_does_not_send_deprecation_headers(): void
    {
        Challenge::factory()->create();

        $response = $this->getJson('/api/v2/challenges')->assertOk();

        $this->assertNull($response->headers->get('Sunset'));
    }
}
