<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Enums\JoiningStatus;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CandidateJoining>
 */
class CandidateJoiningFactory extends Factory
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
            'expected_doj' => now()->addWeeks(2),
            'status' => JoiningStatus::Expected,
            'documents_status' => DocumentStatus::Pending,
        ];
    }
}
