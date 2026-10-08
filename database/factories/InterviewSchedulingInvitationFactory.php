<?php

namespace Database\Factories;

use App\Models\CandidateApplication;
use App\Models\InterviewSchedulingInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InterviewSchedulingInvitation>
 */
class InterviewSchedulingInvitationFactory extends Factory
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
            'expires_at' => now()->addDays(7),
        ];
    }
}
