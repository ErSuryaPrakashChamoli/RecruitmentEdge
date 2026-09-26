<?php

namespace App\Services;

use App\Enums\DuplicateMatchType;
use App\Models\Candidate;

/**
 * One structured duplicate-detection result (Phase 4): the existing candidate, the strongest
 * signal that matched, a fixed confidence, every field that matched and a human-readable reason.
 */
final readonly class DuplicateCandidateMatch
{
    /**
     * @param  array<int, string>  $matchingFields
     */
    public function __construct(
        public Candidate $candidate,
        public DuplicateMatchType $type,
        public int $confidence,
        public array $matchingFields,
        public string $reason,
    ) {}

    public function isStrong(): bool
    {
        return $this->confidence >= CandidateDuplicateDetector::STRONG_CONFIDENCE;
    }

    /**
     * A display summary safe for a user who may not be allowed to open the existing candidate:
     * name plus masked contact details only.
     *
     * @return array{candidate_code: string, name: string, mobile: string, email: string, match: string, confidence: int}
     */
    public function maskedSummary(): array
    {
        return [
            'candidate_code' => $this->candidate->candidate_code,
            'name' => $this->candidate->full_name,
            'mobile' => CandidateIdentityNormalizer::maskMobile($this->candidate->mobile),
            'email' => CandidateIdentityNormalizer::maskEmail($this->candidate->email),
            'match' => $this->type->label(),
            'confidence' => $this->confidence,
        ];
    }

    /**
     * For audit logs and AI context — identifiers only, never raw contact details.
     *
     * @return array{candidate_id: int, match_type: string, confidence: int, matching_fields: array<int, string>, reason: string}
     */
    public function toArray(): array
    {
        return [
            'candidate_id' => $this->candidate->id,
            'match_type' => $this->type->value,
            'confidence' => $this->confidence,
            'matching_fields' => $this->matchingFields,
            'reason' => $this->reason,
        ];
    }
}
