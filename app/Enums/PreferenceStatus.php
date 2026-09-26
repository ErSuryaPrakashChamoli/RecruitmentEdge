<?php

namespace App\Enums;

enum PreferenceStatus: string
{
    case Allowed = 'allowed';
    case OptedOut = 'opted_out';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Allowed => 'Allowed',
            self::OptedOut => 'Opted out',
            self::Unknown => 'Unknown',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Allowed => 'success',
            self::OptedOut => 'danger',
            self::Unknown => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
