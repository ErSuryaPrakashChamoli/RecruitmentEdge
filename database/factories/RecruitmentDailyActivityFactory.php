<?php

namespace Database\Factories;

use App\Enums\ActivityOutcome;
use App\Enums\ActivityType;
use App\Models\Employee;
use App\Models\RecruitmentDailyActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecruitmentDailyActivity>
 */
class RecruitmentDailyActivityFactory extends Factory
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
            'activity_type' => fake()->randomElement(ActivityType::cases()),
            'activity_datetime' => now(),
            'outcome' => fake()->randomElement(ActivityOutcome::cases()),
        ];
    }
}
