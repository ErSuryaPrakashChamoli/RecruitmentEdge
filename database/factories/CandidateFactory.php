<?php

namespace Database\Factories;

use App\Models\Candidate;
use App\Models\CandidateSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Candidate>
 */
class CandidateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'candidate_code' => 'CAND-'.now()->year.'-'.fake()->unique()->numerify('######'),
            'full_name' => fake()->name(),
            'mobile' => fake()->unique()->numerify('9#########'),
            'email' => fake()->unique()->safeEmail(),
            'location' => fake()->city(),
            'current_city' => fake()->city(),
            'qualification' => fake()->randomElement(['Any Graduate', "Bachelor's Degree", "Master's Degree"]),
            'total_experience' => fake()->randomFloat(1, 0, 15),
            'relevant_experience' => fake()->randomFloat(1, 0, 10),
            'current_company' => fake()->company(),
            'current_designation' => fake()->jobTitle(),
            'current_salary' => fake()->numberBetween(200000, 1500000),
            'expected_salary' => fake()->numberBetween(250000, 2000000),
            'notice_period_days' => fake()->randomElement([0, 15, 30, 60, 90]),
            'skills' => fake()->randomElements(['PHP', 'Laravel', 'Sales', 'Communication', 'Excel', 'SQL'], 3),
            'source_id' => CandidateSource::factory(),
        ];
    }
}
