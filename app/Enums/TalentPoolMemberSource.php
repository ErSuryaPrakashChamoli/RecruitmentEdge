<?php

namespace App\Enums;

/**
 * How a candidate came to be in a talent pool. Rediscovery and Ai are reserved for Phase 7 Talent
 * Rediscovery, which will add members through TalentPoolService like any other source.
 */
enum TalentPoolMemberSource: string
{
    case Manual = 'manual';
    case Bulk = 'bulk';
    case Moved = 'moved';
    case Referral = 'referral';
    case Application = 'application';
    case Rediscovery = 'rediscovery';
    case Ai = 'ai';
    case Import = 'import';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Bulk => 'Bulk add',
            self::Moved => 'Moved from another pool',
            self::Referral => 'Referral',
            self::Application => 'Previous application',
            self::Rediscovery => 'Rediscovery',
            self::Ai => 'AI suggestion',
            self::Import => 'Import',
        };
    }
}
