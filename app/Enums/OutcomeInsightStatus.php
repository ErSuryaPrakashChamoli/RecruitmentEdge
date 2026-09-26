<?php

namespace App\Enums;

/**
 * Review lifecycle of an Outcome Loop insight (Phase 8.2). Only Accepted affects Role DNA or
 * Hiring Memory; Review is never treated as established truth.
 */
enum OutcomeInsightStatus: string
{
    case Review = 'review';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Deferred = 'deferred';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Review => 'Awaiting review',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
            self::Deferred => 'Deferred',
            self::Expired => 'Expired',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Review => 'warning',
            self::Accepted => 'success',
            self::Rejected, self::Expired => 'gray',
            self::Deferred => 'info',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Review, self::Deferred], true);
    }
}
