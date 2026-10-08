<?php

namespace App\Enums;

/**
 * Lifecycle of a detected hiring risk.
 */
enum HiringRiskStatus: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Acknowledged => 'Acknowledged',
            self::Resolved => 'Resolved',
            self::Dismissed => 'Dismissed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'danger',
            self::Acknowledged => 'warning',
            self::Resolved => 'success',
            self::Dismissed => 'gray',
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
