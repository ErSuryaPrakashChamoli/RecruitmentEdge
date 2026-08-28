<?php

namespace App\Enums;

/**
 * Shared by RecruitmentRequisition and CandidateApplication — priority is the same concept
 * (urgency of attention) in both places, so it is not duplicated as two near-identical enums.
 */
enum Priority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Medium => 'Medium',
            self::High => 'High',
            self::Urgent => 'Urgent',
        };
    }
}
