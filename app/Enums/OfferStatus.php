<?php

namespace App\Enums;

enum OfferStatus: string
{
    case Draft = 'draft';
    case Initiated = 'initiated';
    case Released = 'released';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Initiated => 'Initiated',
            self::Released => 'Released',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
            self::Withdrawn => 'Withdrawn',
        };
    }

    /**
     * The single source of truth for this status's badge color (Section 32).
     */
    public function color(): string
    {
        return match ($this) {
            self::Accepted => 'success',
            self::Rejected, self::Expired, self::Withdrawn => 'danger',
            self::Initiated, self::Released => 'warning',
            self::Draft => 'gray',
        };
    }
}
