<?php

namespace App\Enums;

/**
 * The structured, candidate-linked contact activities recruiters log in `recruitment_daily_activities`
 * — the authoritative fact source behind connection %/interest % (Section 8). Deliberately does
 * NOT include "Profile Sourced", "Screened", "Selected", etc. — those are already system facts
 * derivable from `candidates.created_at` and `candidate_stage_histories`, so logging them again
 * here would create a second, potentially conflicting source of truth. See
 * RecruiterDailyMetricsService.
 */
enum ActivityType: string
{
    case Call = 'call';
    case WhatsApp = 'whatsapp';
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::Call => 'Call',
            self::WhatsApp => 'WhatsApp',
            self::Email => 'Email',
        };
    }
}
