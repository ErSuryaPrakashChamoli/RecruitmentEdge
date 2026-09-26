<?php

namespace App\Enums;

/**
 * How much history an outcome aggregate rests on (Phase 8.2). Below the insufficient threshold
 * (config outcomes.sample.insufficient_below, the Role DNA MIN_HISTORY of 3) no rate is shown and
 * no learning is suggested — the count alone is reported.
 */
enum OutcomeSampleBand: string
{
    case Insufficient = 'insufficient';
    case Limited = 'limited';
    case Stronger = 'stronger';

    public static function forSize(int $size): self
    {
        return match (true) {
            $size < (int) config('outcomes.sample.insufficient_below', 3) => self::Insufficient,
            $size < (int) config('outcomes.sample.stronger_from', 10) => self::Limited,
            default => self::Stronger,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Insufficient => 'Insufficient history',
            self::Limited => 'Limited history',
            self::Stronger => 'Stronger historical basis',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Insufficient => 'gray',
            self::Limited => 'warning',
            self::Stronger => 'success',
        };
    }

    public function isSufficient(): bool
    {
        return $this !== self::Insufficient;
    }
}
