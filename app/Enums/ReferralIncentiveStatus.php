<?php

namespace App\Enums;

/**
 * The referral's view of its bonus. Payment itself is never tracked here: once Calculated, the
 * linked RecruiterIncentiveCalculation (existing incentive engine) owns approval and payout.
 */
enum ReferralIncentiveStatus: string
{
    case NotEligible = 'not_eligible';
    case Eligible = 'eligible';
    case Calculated = 'calculated';
    case NoMatchingRule = 'no_matching_rule';
    case Forfeited = 'forfeited';

    public function label(): string
    {
        return match ($this) {
            self::NotEligible => 'Not eligible',
            self::Eligible => 'Eligible (on joining)',
            self::Calculated => 'Calculated',
            self::NoMatchingRule => 'No matching incentive rule',
            self::Forfeited => 'Forfeited',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Calculated => 'success',
            self::Eligible => 'info',
            self::NoMatchingRule => 'warning',
            self::NotEligible, self::Forfeited => 'gray',
        };
    }
}
