<?php

namespace App\Enums;

/**
 * Status of one Hiring Health metric against its threshold.
 */
enum MetricStatus: string
{
    case Ok = 'ok';
    case Watch = 'watch';
    case Breach = 'breach';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'OK',
            self::Watch => 'Watch',
            self::Breach => 'Breach',
            self::Unknown => 'Unknown',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::Watch => 'info',
            self::Breach => 'danger',
            self::Unknown => 'gray',
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
