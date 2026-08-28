<?php

namespace Database\Factories;

use App\Enums\DuplicateMatchStatus;
use App\Enums\DuplicateMatchType;
use App\Models\Candidate;
use App\Models\CandidateDuplicateMatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CandidateDuplicateMatch>
 */
class CandidateDuplicateMatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'candidate_id' => Candidate::factory(),
            'matched_candidate_id' => Candidate::factory(),
            'match_type' => fake()->randomElement(DuplicateMatchType::cases()),
            'status' => DuplicateMatchStatus::PendingReview,
        ];
    }
}
