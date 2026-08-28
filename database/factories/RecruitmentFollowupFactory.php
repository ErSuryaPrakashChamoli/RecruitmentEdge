<?php

namespace Database\Factories;

use App\Enums\FollowupStatus;
use App\Enums\FollowupType;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\RecruitmentFollowup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecruitmentFollowup>
 */
class RecruitmentFollowupFactory extends Factory
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
            'recruiter_id' => Employee::factory(),
            'followup_type' => fake()->randomElement(FollowupType::cases()),
            'followup_date' => now()->addDay(),
            'status' => FollowupStatus::Pending,
        ];
    }
}
