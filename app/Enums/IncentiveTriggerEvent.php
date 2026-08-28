<?php

namespace App\Enums;

/**
 * When an incentive rule's calculation is triggered (Section 24 — "Selection", "Joining" are
 * both listed as configurable factors). Only Joining is currently wired to fire automatically
 * (CandidateJoiningService::markJoined(), per Section 25's emphasis that joining incentives must
 * never be paid on selection alone); Selection/OfferAccepted-triggered rules are fully supported
 * by RecruiterIncentiveCalculator but must be run manually via the "Calculate Incentives" action
 * until a future phase wires them into the relevant stage transitions.
 */
enum IncentiveTriggerEvent: string
{
    case Selection = 'selection';
    case OfferAccepted = 'offer_accepted';
    case Joining = 'joining';

    public function label(): string
    {
        return match ($this) {
            self::Selection => 'On Selection',
            self::OfferAccepted => 'On Offer Accepted',
            self::Joining => 'On Joining',
        };
    }
}
