<?php

namespace App\Enums;

/**
 * What kind of fact an intelligence evidence row records (Phase 7). Deterministic kinds are facts; AI kinds are suggestions or inferences and are always labelled as such.
 */
enum EvidenceType: string
{
    case DatabaseFact = 'database_fact';
    case CalculatedMetric = 'calculated_metric';
    case Configuration = 'configuration';
    case RecruiterInput = 'recruiter_input';
    case InterviewerFeedback = 'interviewer_feedback';
    case HumanConfirmation = 'human_confirmation';
    case AiExtraction = 'ai_extraction';
    case AiInference = 'ai_inference';

    public function label(): string
    {
        return match ($this) {
            self::DatabaseFact => 'Recorded fact',
            self::CalculatedMetric => 'Calculated metric',
            self::Configuration => 'Configured requirement',
            self::RecruiterInput => 'Recruiter input',
            self::InterviewerFeedback => 'Interviewer feedback',
            self::HumanConfirmation => 'Confirmed by a person',
            self::AiExtraction => 'AI extraction',
            self::AiInference => 'AI suggestion',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DatabaseFact => 'gray',
            self::CalculatedMetric => 'info',
            self::Configuration => 'primary',
            self::RecruiterInput => 'gray',
            self::InterviewerFeedback => 'gray',
            self::HumanConfirmation => 'success',
            self::AiExtraction => 'warning',
            self::AiInference => 'warning',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }
}
