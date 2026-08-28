<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\RecruiterPerformanceSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecruiterPerformanceSnapshot>
 */
class RecruiterPerformanceSnapshotFactory extends Factory
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
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'score' => fake()->randomFloat(2, 0, 150),
            'breakdown' => [],
            'computed_at' => now(),
        ];
    }
}
