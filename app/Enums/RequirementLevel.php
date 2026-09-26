<?php

namespace App\Enums;

/**
 * How strongly a Role DNA attribute is required.
 */
enum RequirementLevel: string
{
    case Required = 'required';
    case Preferred = 'preferred';
    case Informational = 'informational';

    public function label(): string
    {
        return match ($this) {
            self::Required => 'Required',
            self::Preferred => 'Preferred',
            self::Informational => 'Informational',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Required => 'danger',
            self::Preferred => 'warning',
            self::Informational => 'gray',
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
