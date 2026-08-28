<?php

namespace Database\Factories;

use App\Enums\InterviewMode;
use App\Enums\InterviewStatus;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Interview>
 */
class InterviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'candidate_application_id' => CandidateApplication::factory(),
            'round_number' => 1,
            'interviewer_id' => Employee::factory(),
            'scheduled_at' => now()->addDay(),
            'mode' => fake()->randomElement(InterviewMode::cases()),
            'status' => InterviewStatus::Scheduled,
        ];
    }
}
