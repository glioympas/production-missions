<?php

namespace Database\Factories;

use App\Models\Solve;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Solve>
 */
class SolveFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'challenge_name' => fake()->words(2, true),
        ];
    }
}
