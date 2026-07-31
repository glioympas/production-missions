<?php

namespace Database\Factories;

use App\Models\Submission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Submission>
 */
class SubmissionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_name' => fake()->company(),
            'challenge_name' => fake()->words(2, true),
            'points' => fake()->numberBetween(50, 500),
        ];
    }
}
