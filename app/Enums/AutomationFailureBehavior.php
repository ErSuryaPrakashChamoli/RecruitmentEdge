<?php

namespace App\Enums;

/**
 * What an execution does when one of its actions fails.
 */
enum AutomationFailureBehavior: string
{
    case Continue = 'continue';
    case Stop = 'stop';

    public function label(): string
    {
        return match ($this) {
            self::Continue => 'Continue with the remaining actions',
            self::Stop => 'Stop the remaining actions',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Continue => 'gray',
            self::Stop => 'warning',
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
