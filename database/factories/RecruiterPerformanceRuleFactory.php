<?php

namespace Database\Factories;

use App\Enums\TargetMetric;
use App\Models\RecruiterPerformanceRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecruiterPerformanceRule>
 */
class RecruiterPerformanceRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'metric' => fake()->randomElement(TargetMetric::cases()),
            'weightage' => fake()->numberBetween(10, 40),
            'effective_from' => now()->startOfMonth(),
        ];
    }
}
