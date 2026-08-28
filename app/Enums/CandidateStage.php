<?php

namespace App\Enums;

/**
 * The canonical pipeline order (Sourced -> ... -> Onboarding Completed). Kept as a fixed enum
 * order rather than a freely configurable list so funnel/conversion analytics stay well-defined —
 * see StageTransitionService, which is the only code path allowed to change an application's
 * stage.
 */
enum CandidateStage: string
{
    case Sourced = 'sourced';
    case ContactAttempted = 'contact_attempted';
    case Connected = 'connected';
    case Interested = 'interested';
    case Screened = 'screened';
    case Shortlisted = 'shortlisted';
    case InterviewScheduled = 'interview_scheduled';
    case Interview1 = 'interview_1';
    case Interview2 = 'interview_2';
    case FinalInterview = 'final_interview';
    case Selected = 'selected';
    case OfferInitiated = 'offer_initiated';
    case OfferReleased = 'offer_released';
    case OfferAccepted = 'offer_accepted';
    case JoiningConfirmed = 'joining_confirmed';
    case Joined = 'joined';
    case DocumentsCompleted = 'documents_completed';
    case OnboardingCompleted = 'onboarding_completed';

    public function label(): string
    {
        return match ($this) {
            self::Sourced => 'Sourced',
            self::ContactAttempted => 'Contact Attempted',
            self::Connected => 'Connected',
            self::Interested => 'Interested',
            self::Screened => 'Screened',
            self::Shortlisted => 'Shortlisted',
            self::InterviewScheduled => 'Interview Scheduled',
            self::Interview1 => 'Interview 1',
            self::Interview2 => 'Interview 2',
            self::FinalInterview => 'Final Interview',
            self::Selected => 'Selected',
            self::OfferInitiated => 'Offer Initiated',
            self::OfferReleased => 'Offer Released',
            self::OfferAccepted => 'Offer Accepted',
            self::JoiningConfirmed => 'Joining Confirmed',
            self::Joined => 'Joined',
            self::DocumentsCompleted => 'Documents Completed',
            self::OnboardingCompleted => 'Onboarding Completed',
        };
    }

    /**
     * Position in the canonical pipeline order, starting at 0. Used by StageTransitionService to
     * decide whether a requested move is forward (allowed) or backward (rejected).
     */
    public function order(): int
    {
        return array_search($this, self::cases(), strict: true);
    }
}
