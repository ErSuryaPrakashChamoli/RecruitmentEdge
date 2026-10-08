<?php

namespace App\Services\Intelligence\Data;

use App\Enums\SignalBand;

/**
 * Output of TalentSignalCalculator: the band, every component (label, status, summary, details),
 * the evidence behind it and short "why" lines.
 */
final readonly class TalentSignalResult
{
    /**
     * @param  array<string, array<string, mixed>>  $components
     * @param  array<int, EvidenceItem>  $evidence
     * @param  array<int, string>  $reasons
     */
    public function __construct(
        public SignalBand $band,
        public array $components,
        public array $evidence,
        public int $requiredSkills,
        public int $requiredMatched,
        public ?float $coverage,
        public string $experienceFit,
        public float $completeness,
        public array $reasons,
    ) {}
}
