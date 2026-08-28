<?php

namespace Database\Factories;

use App\Enums\IncentiveCalculationStatus;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\RecruitmentIncentiveRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecruiterIncentiveCalculation>
 */
class RecruiterIncentiveCalculationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'incentive_rule_id' => RecruitmentIncentiveRule::factory(),
            'employee_id' => Employee::factory(),
            'candidate_id' => Candidate::factory(),
            'candidate_application_id' => CandidateApplication::factory(),
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'amount' => fake()->numberBetween(500, 3000),
            'status' => IncentiveCalculationStatus::PendingVerification,
            'calculated_at' => now(),
        ];
    }
}
