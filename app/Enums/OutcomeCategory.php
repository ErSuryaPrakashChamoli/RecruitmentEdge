<?php

namespace App\Enums;

/**
 * Groups of Outcome Loop outcome types (Phase 8.2).
 */
enum OutcomeCategory: string
{
    case Joining = 'joining';
    case Offer = 'offer';
    case Process = 'process';
    case Source = 'source';
    case Retention = 'retention';

    public function label(): string
    {
        return match ($this) {
            self::Joining => 'Joining',
            self::Offer => 'Offer',
            self::Process => 'Hiring process',
            self::Source => 'Source',
            self::Retention => 'Status observation',
        };
    }
}
