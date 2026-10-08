<?php

namespace App\Enums;

/**
 * Where a Role DNA attribute came from. Only configured, human-confirmed (and deterministic inferred/historical) attributes drive signals; AI suggestions do not until a person confirms them.
 */
enum RoleDnaOrigin: string
{
    case Configured = 'configured';
    case Inferred = 'inferred';
    case Historical = 'historical';
    case AiSuggestion = 'ai_suggestion';
    case HumanConfirmed = 'human_confirmed';

    public function label(): string
    {
        return match ($this) {
            self::Configured => 'Configured requirement',
            self::Inferred => 'Inferred',
            self::Historical => 'Historical pattern',
            self::AiSuggestion => 'AI suggestion (unconfirmed)',
            self::HumanConfirmed => 'Confirmed by a person',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Configured => 'primary',
            self::Inferred => 'info',
            self::Historical => 'gray',
            self::AiSuggestion => 'warning',
            self::HumanConfirmed => 'success',
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
