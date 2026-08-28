<?php

namespace Database\Factories;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\Priority;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\RecruitmentRequisition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CandidateApplication>
 */
class CandidateApplicationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'application_code' => 'APP-'.now()->year.'-'.fake()->unique()->numerify('######'),
            'candidate_id' => Candidate::factory(),
            'requisition_id' => RecruitmentRequisition::factory(),
            'recruiter_id' => Employee::factory(),
            'current_stage' => CandidateStage::Sourced,
            'application_date' => now(),
            'priority' => fake()->randomElement(Priority::cases()),
            'status' => ApplicationStatus::Active,
        ];
    }
}
