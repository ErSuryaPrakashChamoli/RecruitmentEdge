<?php

namespace App\Enums;

/**
 * Kinds of risk the Hiring Risk Radar detects. A risk is a warning, never a hiring decision.
 */
enum HiringRiskType: string
{
    case RequisitionAging = 'requisition_aging';
    case ThinPipeline = 'thin_pipeline';
    case SourcingStalled = 'sourcing_stalled';
    case SourceDependency = 'source_dependency';
    case CandidateDropOff = 'candidate_drop_off';
    case SchedulingDelay = 'scheduling_delay';
    case StalledCandidates = 'stalled_candidates';
    case OfferRisk = 'offer_risk';
    case JoiningRisk = 'joining_risk';
    case RecruiterSla = 'recruiter_sla';
    case InterviewerBottleneck = 'interviewer_bottleneck';
    case AutomationFailures = 'automation_failures';
    case DataQuality = 'data_quality';

    public function label(): string
    {
        return match ($this) {
            self::RequisitionAging => 'Requisition ageing',
            self::ThinPipeline => 'Thin pipeline',
            self::SourcingStalled => 'Sourcing stalled',
            self::SourceDependency => 'Source dependency',
            self::CandidateDropOff => 'Candidate drop-off',
            self::SchedulingDelay => 'Scheduling delay',
            self::StalledCandidates => 'Stalled candidates',
            self::OfferRisk => 'Offer risk',
            self::JoiningRisk => 'Joining risk',
            self::RecruiterSla => 'Recruiter SLA risk',
            self::InterviewerBottleneck => 'Interviewer bottleneck',
            self::AutomationFailures => 'Automation failures',
            self::DataQuality => 'Data quality',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::RequisitionAging => 'warning',
            self::ThinPipeline => 'warning',
            self::SourcingStalled => 'warning',
            self::SourceDependency => 'info',
            self::CandidateDropOff => 'warning',
            self::SchedulingDelay => 'warning',
            self::StalledCandidates => 'warning',
            self::OfferRisk => 'warning',
            self::JoiningRisk => 'danger',
            self::RecruiterSla => 'warning',
            self::InterviewerBottleneck => 'warning',
            self::AutomationFailures => 'warning',
            self::DataQuality => 'gray',
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
