<?php

namespace App\Enums;

/**
 * Phase 8.3: lifecycle of a set of offer terms. Released is the terms currently in force;
 * Superseded is an earlier release replaced by a later one; Pending awaits release; Cancelled was
 * withdrawn before release.
 */
enum OfferRevisionStatus: string
{
    case Pending = 'pending';
    case Released = 'released';
    case Superseded = 'superseded';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting release',
            self::Released => 'Released (in force)',
            self::Superseded => 'Superseded',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Released => 'success',
            self::Superseded, self::Cancelled => 'gray',
        };
    }
}
