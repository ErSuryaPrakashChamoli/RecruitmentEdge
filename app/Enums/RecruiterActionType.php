<?php

namespace App\Enums;

/**
 * What a recruiter action asks the owner to do. Next Best Action and automation rules create these; Custom is for manually assigned work.
 */
enum RecruiterActionType: string
{
    case ConfirmInterview = 'confirm_interview';
    case CollectFeedback = 'collect_feedback';
    case FollowUp = 'follow_up';
    case InitiateOffer = 'initiate_offer';
    case ChaseOfferDecision = 'chase_offer_decision';
    case ConfirmJoining = 'confirm_joining';
    case SourceCandidates = 'source_candidates';
    case ReviewApplication = 'review_application';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::ConfirmInterview => 'Confirm interview',
            self::CollectFeedback => 'Collect interview feedback',
            self::FollowUp => 'Follow up with candidate',
            self::InitiateOffer => 'Initiate offer',
            self::ChaseOfferDecision => 'Chase offer decision',
            self::ConfirmJoining => 'Confirm joining',
            self::SourceCandidates => 'Source candidates',
            self::ReviewApplication => 'Review application',
            self::Custom => 'Other',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ConfirmInterview => 'warning',
            self::CollectFeedback => 'warning',
            self::FollowUp => 'info',
            self::InitiateOffer => 'warning',
            self::ChaseOfferDecision => 'info',
            self::ConfirmJoining => 'warning',
            self::SourceCandidates => 'info',
            self::ReviewApplication => 'gray',
            self::Custom => 'gray',
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
