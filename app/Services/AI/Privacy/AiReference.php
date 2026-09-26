<?php

namespace App\Services\AI\Privacy;

use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\HiringRisk;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\RecruitmentFollowup;
use App\Models\RecruitmentRequisition;

/**
 * The stable, AI-safe reference for a record (Phase 8.1). The AI provider only ever sees these
 * references — never names or contact details. Records with a business code (candidates,
 * applications, employees, requisitions, offers) use it as-is; records without one get a
 * prefix + id reference. AiReferenceResolver turns references back into names for an authorized
 * viewer at render time only.
 */
final class AiReference
{
    /**
     * Matches every reference this class produces, e.g. CAND-2026-000123, EMP-000014, INT-42.
     */
    public const string PATTERN = '/\b(?:(?:CAND|APP|REQ|OFR)-\d{4}-\d{6}|EMP-\d{6}|(?:INT|JOIN|FUP|RISK)-\d+)\b/';

    public static function candidate(?Candidate $candidate): ?string
    {
        return $candidate?->candidate_code;
    }

    public static function application(?CandidateApplication $application): ?string
    {
        return $application?->application_code;
    }

    public static function employee(?Employee $employee): ?string
    {
        return $employee?->employee_code;
    }

    public static function requisition(?RecruitmentRequisition $requisition): ?string
    {
        return $requisition?->code;
    }

    public static function offer(?Offer $offer): ?string
    {
        return $offer?->offer_code;
    }

    public static function interview(?Interview $interview): ?string
    {
        return $interview !== null ? 'INT-'.$interview->getKey() : null;
    }

    public static function joining(?CandidateJoining $joining): ?string
    {
        return $joining !== null ? 'JOIN-'.$joining->getKey() : null;
    }

    public static function followup(?RecruitmentFollowup $followup): ?string
    {
        return $followup !== null ? 'FUP-'.$followup->getKey() : null;
    }

    public static function risk(?HiringRisk $risk): ?string
    {
        return $risk !== null ? 'RISK-'.$risk->getKey() : null;
    }
}
