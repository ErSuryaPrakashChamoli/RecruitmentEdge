<?php

namespace App\Enums;

/**
 * Phase 8.3 adds Cancelled: the joining will not happen because the company closed the application
 * (e.g. rejected it) — distinct from a no-show or a candidate dropout. It records no joining outcome.
 */
enum JoiningStatus: string
{
    case Expected = 'expected';
    case Confirmed = 'confirmed';
    case Joined = 'joined';
    case NoShow = 'no_show';
    case Dropout = 'dropout';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Expected => 'Expected',
            self::Confirmed => 'Confirmed',
            self::Joined => 'Joined',
            self::NoShow => 'No Show',
            self::Dropout => 'Dropout',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * The single source of truth for this status's badge color (Section 32).
     */
    public function color(): string
    {
        return match ($this) {
            self::Joined => 'success',
            self::Confirmed => 'info',
            self::NoShow, self::Dropout => 'danger',
            self::Expected, self::Cancelled => 'gray',
        };
    }
}
