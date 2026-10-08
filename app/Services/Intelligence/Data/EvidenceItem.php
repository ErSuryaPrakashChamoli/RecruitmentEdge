<?php

namespace App\Services\Intelligence\Data;

use App\Enums\EvidenceType;
use App\Enums\VerificationStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * One piece of evidence before it is persisted (EDGE Intelligence). Deterministic kinds carry no
 * confidence and need no verification; AI kinds start Unverified and name their model.
 */
final readonly class EvidenceItem
{
    public function __construct(
        public EvidenceType $type,
        public string $label,
        public ?string $value = null,
        public ?string $subjectKey = null,
        public ?float $numericValue = null,
        public ?Model $source = null,
        public ?CarbonInterface $observedAt = null,
        public ?string $explanation = null,
        public ?float $confidence = null,
        public ?string $aiModel = null,
        public ?VerificationStatus $verification = null,
    ) {}

    public static function fact(string $subjectKey, string $label, ?string $value, ?Model $source = null, ?CarbonInterface $observedAt = null, ?string $explanation = null, ?float $numeric = null): self
    {
        return new self(EvidenceType::DatabaseFact, $label, $value, $subjectKey, $numeric, $source, $observedAt ?? $source?->updated_at, $explanation);
    }

    public static function metric(string $subjectKey, string $label, ?string $value, ?float $numeric = null, ?string $explanation = null, ?Model $source = null): self
    {
        return new self(EvidenceType::CalculatedMetric, $label, $value, $subjectKey, $numeric, $source, now(), $explanation);
    }

    public static function configured(string $subjectKey, string $label, ?string $value, ?Model $source = null, ?string $explanation = null): self
    {
        return new self(EvidenceType::Configuration, $label, $value, $subjectKey, null, $source, $source?->updated_at, $explanation);
    }

    public static function interviewer(string $subjectKey, string $label, ?string $value, ?Model $source = null, ?float $numeric = null): self
    {
        return new self(EvidenceType::InterviewerFeedback, $label, $value, $subjectKey, $numeric, $source, $source?->created_at);
    }

    public static function confirmation(string $subjectKey, string $label, ?string $value, ?Model $source = null): self
    {
        return new self(EvidenceType::HumanConfirmation, $label, $value, $subjectKey, null, $source, now());
    }

    public static function ai(string $subjectKey, string $label, ?string $value, string $model, ?string $explanation = null): self
    {
        return new self(EvidenceType::AiInference, $label, $value, $subjectKey, null, null, now(), $explanation, null, $model);
    }

    public function verificationStatus(): VerificationStatus
    {
        if ($this->verification !== null) {
            return $this->verification;
        }

        return in_array($this->type, [EvidenceType::AiExtraction, EvidenceType::AiInference], true)
            ? VerificationStatus::Unverified
            : VerificationStatus::NotRequired;
    }
}
