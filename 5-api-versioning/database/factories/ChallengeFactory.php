<?php

namespace Database\Factories;

use App\Models\Challenge;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Challenge>
 */
class ChallengeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->words(3, true),
            'base_points' => fake()->numberBetween(100, 500),
            'first_blood_bonus' => fake()->numberBetween(0, 100),
        ];
    }
}
