<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Models\CandidateJoining;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CandidateDocument>
 */
class CandidateDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'candidate_joining_id' => CandidateJoining::factory(),
            'document_type' => fake()->randomElement(DocumentType::cases()),
            'status' => DocumentStatus::Pending,
        ];
    }

    /**
     * A candidate-level document attached before any joining record exists.
     */
    public function forCandidate(?Candidate $candidate = null): static
    {
        return $this->state(fn (): array => [
            'candidate_joining_id' => null,
            'candidate_id' => $candidate?->id ?? Candidate::factory(),
        ]);
    }
}
