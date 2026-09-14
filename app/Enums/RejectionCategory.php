<?php

namespace App\Enums;

/**
 * Groups rejection/dropout reasons in every reason dropdown (Select option groups, via
 * RecruitmentRejectionReason::groupedActiveOptions()), in the case order declared here. Only
 * active reasons are offered, and StageTransitionService refuses an inactive one.
 */
enum RejectionCategory: string
{
    case General = 'general';
    case Interview = 'interview';
    case Offer = 'offer';
    case Joining = 'joining';

    public function label(): string
    {
        return match ($this) {
            self::General => 'General',
            self::Interview => 'Interview',
            self::Offer => 'Offer',
            self::Joining => 'Joining',
        };
    }
}
