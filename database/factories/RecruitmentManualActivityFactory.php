<?php

namespace Database\Factories;

use App\Enums\TargetMetric;
use App\Models\Employee;
use App\Models\RecruitmentManualActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecruitmentManualActivity>
 */
class RecruitmentManualActivityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recruiter_id' => Employee::factory(),
            'activity_date' => now(),
            'metric' => fake()->randomElement(TargetMetric::cases())->value,
            'count' => fake()->numberBetween(1, 20),
        ];
    }
}
