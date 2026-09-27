<?php

namespace App\Enums;

/**
 * Units and their canonical rounding (Phase 8.5 D18): rates 1 dp, days 1 dp, money 2 dp, scores
 * 2 dp, counts whole.
 */
enum MetricUnit: string
{
    case Percent = 'percent';
    case Days = 'days';
    case Hours = 'hours';
    case Currency = 'currency';
    case Count = 'count';
    case Score = 'score';

    public function decimals(): int
    {
        return match ($this) {
            self::Percent, self::Days, self::Hours => 1,
            self::Currency, self::Score => 2,
            self::Count => 0,
        };
    }

    public function round(float $value): float
    {
        return round($value, $this->decimals(), PHP_ROUND_HALF_UP);
    }

    public function format(float $value): string
    {
        return match ($this) {
            self::Percent => number_format($value, 1).'%',
            self::Days => number_format($value, 1).' days',
            self::Hours => number_format($value, 1).' hours',
            self::Currency => '₹'.number_format($value, 2),
            self::Count => number_format($value),
            self::Score => number_format($value, 2),
        };
    }
}
