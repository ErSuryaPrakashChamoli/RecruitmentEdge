<?php

namespace Database\Factories;

use App\Enums\TargetMetric;
use App\Enums\TargetPeriodType;
use App\Models\Employee;
use App\Models\RecruitmentDailyTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecruitmentDailyTarget>
 */
class RecruitmentDailyTargetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'metric' => fake()->randomElement(TargetMetric::cases()),
            'period_type' => TargetPeriodType::Daily,
            'target_value' => fake()->numberBetween(10, 100),
            'effective_from' => now()->startOfMonth(),
        ];
    }
}
