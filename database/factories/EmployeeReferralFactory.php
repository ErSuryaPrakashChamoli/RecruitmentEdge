<?php

namespace Database\Factories;

use App\Enums\ReferralIncentiveStatus;
use App\Enums\ReferralRelationship;
use App\Enums\ReferralStatus;
use App\Models\Candidate;
use App\Models\Employee;
use App\Models\EmployeeReferral;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeReferral>
 */
class EmployeeReferralFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'referral_code' => 'REF-'.now()->year.'-'.fake()->unique()->numerify('######'),
            'referrer_id' => Employee::factory(),
            'candidate_id' => Candidate::factory(),
            'relationship' => ReferralRelationship::FormerColleague,
            'referred_at' => now()->toDateString(),
            'incentive_eligible' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (EmployeeReferral $referral): void {
            $referral->status ??= ReferralStatus::Submitted;
            $referral->incentive_status ??= ReferralIncentiveStatus::Eligible;
        });
    }

    public function status(ReferralStatus $status): static
    {
        return $this->afterMaking(fn (EmployeeReferral $referral) => $referral->status = $status);
    }
}
