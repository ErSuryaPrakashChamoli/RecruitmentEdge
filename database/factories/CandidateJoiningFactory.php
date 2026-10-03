<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Offer;
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

    /**
     * Phase 8.10 (P810-DI-04): linked to an accepted offer of the same application, as a joining
     * made through the offer chain is — Mark Joined requires one.
     */
    public function withAcceptedOffer(): static
    {
        return $this->state(fn (): array => [
            'offer_id' => fn (array $attributes): int => Offer::factory()->create([
                'candidate_application_id' => $attributes['candidate_application_id'],
                'status' => OfferStatus::Accepted,
            ])->id,
        ]);
    }
}
