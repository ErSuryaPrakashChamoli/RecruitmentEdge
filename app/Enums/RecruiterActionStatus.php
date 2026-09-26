<?php

namespace App\Enums;

/**
 * Lifecycle of one Action Center item.
 */
enum RecruiterActionStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Dismissed = 'dismissed';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::Dismissed => 'Dismissed',
            self::Expired => 'Expired',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::InProgress => 'info',
            self::Completed => 'success',
            self::Dismissed => 'gray',
            self::Expired => 'danger',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }

    /**
     * Statuses that still need the owner's attention.
     *
     * @return array<int, self>
     */
    public static function openStatuses(): array
    {
        return [self::Open, self::InProgress];
    }
}
