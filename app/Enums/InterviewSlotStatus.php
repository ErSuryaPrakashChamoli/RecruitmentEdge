<?php

namespace App\Enums;

/**
 * Lifecycle of an interviewer availability slot. Held is reserved for a two-step checkout (e.g. a
 * Phase 5 external calendar confirming availability before the booking is final); Phase 4 books
 * atomically under a row lock, so slots move straight from Available to Booked.
 */
enum InterviewSlotStatus: string
{
    case Available = 'available';
    case Held = 'held';
    case Booked = 'booked';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Held => 'Held',
            self::Booked => 'Booked',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Available => 'success',
            self::Held => 'warning',
            self::Booked => 'info',
            self::Expired, self::Cancelled => 'gray',
        };
    }
}
