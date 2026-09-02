<?php

namespace App\Enums;

enum InterviewStatus: string
{
    case Pending = 'pending';
    case Scheduled = 'scheduled';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Hold = 'hold';
    case Rescheduled = 'rescheduled';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Scheduled => 'Scheduled',
            self::Confirmed => 'Confirmed',
            self::Completed => 'Completed',
            self::Hold => 'Hold',
            self::Rescheduled => 'Rescheduled',
            self::Cancelled => 'Cancelled',
            self::NoShow => 'No Show',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::NoShow], true);
    }

    /**
     * The single source of truth for this status's badge color (Section 32).
     */
    public function color(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Scheduled, self::Confirmed => 'info',
            self::Hold, self::Rescheduled => 'warning',
            self::Cancelled, self::NoShow => 'danger',
            self::Pending => 'gray',
        };
    }
}
