<?php

namespace Database\Factories;

use App\Enums\RejectionCategory;
use App\Models\RecruitmentRejectionReason;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecruitmentRejectionReason>
 */
class RecruitmentRejectionReasonFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement([
                'Not Interested', 'Salary Issue', 'Location Issue', 'Experience Mismatch',
                'Qualification Mismatch', 'Notice Period', 'Selected Elsewhere', 'No Response',
                'Interview Rejected', 'Offer Rejected', 'Did Not Join', 'Background Verification',
                'Candidate Withdrew', 'Other',
            ]),
            'code' => strtoupper(fake()->unique()->lexify('RSN-???')),
            'category' => fake()->randomElement(RejectionCategory::cases()),
            'is_active' => true,
        ];
    }
}
