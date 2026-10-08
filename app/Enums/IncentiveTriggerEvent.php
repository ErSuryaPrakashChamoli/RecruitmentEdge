<?php

namespace App\Enums;

/**
 * When an incentive rule's calculation is triggered (Section 24 — "Selection", "Joining" are
 * both listed as configurable factors). Only Joining is currently wired to fire automatically
 * (CandidateJoiningService::markJoined(), per Section 25's emphasis that joining incentives must
 * never be paid on selection alone); Selection/OfferAccepted-triggered rules are fully supported
 * by RecruiterIncentiveCalculator but must be run manually via the "Calculate Incentives" action
 * until a future phase wires them into the relevant stage transitions.
 *
 * ReferralJoining (Phase 4) is the only trigger whose beneficiary is not the application's
 * recruiter but the referring employee; it fires automatically when a referred candidate joins
 * (ReferralService::syncFromApplication()) and is scoped by the referrer's department/designation/
 * location like any other rule.
 */
enum IncentiveTriggerEvent: string
{
    case Selection = 'selection';
    case OfferAccepted = 'offer_accepted';
    case Joining = 'joining';
    case ReferralJoining = 'referral_joining';

    /**
     * Whether calculations for this trigger are paid to the referring employee rather than the
     * recruiter who owns the application.
     */
    public function paysReferrer(): bool
    {
        return $this === self::ReferralJoining;
    }

    public function label(): string
    {
        return match ($this) {
            self::Selection => 'On Selection',
            self::OfferAccepted => 'On Offer Accepted',
            self::Joining => 'On Joining',
            self::ReferralJoining => 'On Referral Joining (pays the referring employee)',
        };
    }

    /**
     * Singular name of one occurrence, e.g. for "Amount per joining".
     */
    public function occurrenceNoun(): string
    {
        return match ($this) {
            self::Selection => 'selection',
            self::OfferAccepted => 'accepted offer',
            self::Joining => 'joining',
            self::ReferralJoining => 'referral joining',
        };
    }

    /**
     * Plural name used for slab-by-count bands, e.g. "1 – 3 joinings".
     */
    public function countNoun(): string
    {
        return match ($this) {
            self::Selection => 'selections',
            self::OfferAccepted => 'accepted offers',
            self::Joining => 'joinings',
            self::ReferralJoining => 'referral joinings',
        };
    }
}
