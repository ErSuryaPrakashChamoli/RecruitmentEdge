<?php

namespace App\Enums;

/**
 * How an incentive rule prices each occurrence of its trigger (for example, each joining).
 * Fixed pays the rule's `fixed_amount` every time; the two slab types pick a RecruitmentIncentiveSlab
 * band by the recruiter's occurrence count or target achievement % for the month.
 */
enum IncentivePayoutType: string
{
    case Fixed = 'fixed';
    case SlabByCount = 'slab_by_count';
    case SlabByAchievement = 'slab_by_achievement';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed rate',
            self::SlabByCount => 'Slab by count',
            self::SlabByAchievement => 'Slab by achievement %',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Fixed => 'The same amount for every occurrence.',
            self::SlabByCount => 'The amount per occurrence depends on how many the recruiter has in the month.',
            self::SlabByAchievement => 'The amount per occurrence depends on the recruiter\'s target achievement % for the month.',
        };
    }

    public function usesSlabs(): bool
    {
        return $this !== self::Fixed;
    }
}
