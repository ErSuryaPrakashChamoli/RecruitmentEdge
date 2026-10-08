<?php

namespace App\Enums;

/**
 * State of one scheduled escalation step. Stopped means the issue was resolved (stop condition met or the linked action completed) before the step became due.
 */
enum EscalationStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Stopped = 'stopped';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Sent => 'Escalated',
            self::Stopped => 'Stopped (resolved)',
            self::Cancelled => 'Cancelled',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Sent => 'warning',
            self::Stopped => 'success',
            self::Cancelled => 'gray',
            self::Failed => 'danger',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }
}
