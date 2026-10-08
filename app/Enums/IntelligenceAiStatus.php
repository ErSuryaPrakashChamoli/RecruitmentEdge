<?php

namespace App\Enums;

/**
 * State of an optional AI contribution. A failed or unavailable result is never shown as intelligence.
 */
enum IntelligenceAiStatus: string
{
    case NotRequested = 'not_requested';
    case Processing = 'processing';
    case Available = 'available';
    case Failed = 'failed';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::NotRequested => 'Not requested',
            self::Processing => 'Processing',
            self::Available => 'Available',
            self::Failed => 'Failed',
            self::Unavailable => 'AI not configured',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NotRequested => 'gray',
            self::Processing => 'info',
            self::Available => 'success',
            self::Failed => 'danger',
            self::Unavailable => 'gray',
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
