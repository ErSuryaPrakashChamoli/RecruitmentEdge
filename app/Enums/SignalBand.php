<?php

namespace App\Enums;

/**
 * Deterministic Talent Signal band (rules talent-signal/1), always shown with its components — never an opaque score.
 */
enum SignalBand: string
{
    case Strong = 'strong';
    case Moderate = 'moderate';
    case Weak = 'weak';
    case InsufficientEvidence = 'insufficient_evidence';

    public function label(): string
    {
        return match ($this) {
            self::Strong => 'Strong alignment',
            self::Moderate => 'Partial alignment',
            self::Weak => 'Low alignment',
            self::InsufficientEvidence => 'Insufficient evidence',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Strong => 'success',
            self::Moderate => 'info',
            self::Weak => 'warning',
            self::InsufficientEvidence => 'gray',
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
