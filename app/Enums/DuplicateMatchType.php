<?php

namespace App\Enums;

/**
 * Deterministic duplicate-candidate signals, strongest first. Confidence is fixed per signal (not
 * learned) so results are explainable and reproducible; Phase 7 AI duplicate intelligence can add
 * probabilistic signals on top without changing these.
 */
enum DuplicateMatchType: string
{
    case Mobile = 'mobile';
    case Email = 'email';
    case NormalizedMobile = 'normalized_mobile';
    case NormalizedEmail = 'normalized_email';
    case AlternateMobile = 'alternate_mobile';
    case NameAndContact = 'name_and_contact';

    public function label(): string
    {
        return match ($this) {
            self::Mobile => 'Mobile',
            self::Email => 'Email',
            self::NormalizedMobile => 'Mobile (normalised)',
            self::NormalizedEmail => 'Email (normalised)',
            self::AlternateMobile => 'Alternate mobile',
            self::NameAndContact => 'Name + partial contact',
        };
    }

    /**
     * 0–100. Signals at or above CandidateDuplicateDetector::STRONG_CONFIDENCE block creating a new
     * candidate without an audited justification.
     */
    public function confidence(): int
    {
        return match ($this) {
            self::Mobile, self::Email => 100,
            self::NormalizedMobile => 95,
            self::NormalizedEmail => 90,
            self::AlternateMobile => 85,
            self::NameAndContact => 70,
        };
    }
}
