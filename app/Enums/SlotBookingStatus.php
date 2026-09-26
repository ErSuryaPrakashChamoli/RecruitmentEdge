<?php

namespace App\Enums;

enum SlotBookingStatus: string
{
    case Booked = 'booked';
    case Rescheduled = 'rescheduled';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Booked => 'Booked',
            self::Rescheduled => 'Rescheduled',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Booked => 'success',
            self::Rescheduled => 'warning',
            self::Cancelled => 'gray',
        };
    }
}
