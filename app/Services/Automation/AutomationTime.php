<?php

namespace App\Services\Automation;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Time arithmetic for automation timing and duration conditions, in the application timezone.
 * Business days skip Saturdays and Sundays only — there is no holiday calendar in the system, so
 * public holidays count as business days (documented limitation).
 */
final class AutomationTime
{
    public const array UNITS = [
        'minutes' => 'Minutes',
        'hours' => 'Hours',
        'days' => 'Calendar days',
        'business_days' => 'Business days (Mon–Fri)',
    ];

    public static function shift(CarbonInterface $from, int $amount, string $unit, bool $forward = true): Carbon
    {
        $moment = Carbon::instance($from)->copy();
        $amount = $forward ? $amount : -$amount;

        return match ($unit) {
            'minutes' => $moment->addMinutes($amount),
            'hours' => $moment->addHours($amount),
            'days' => $moment->addDays($amount),
            'business_days' => $moment->addWeekdays($amount),
            default => throw new InvalidArgumentException("Unknown time unit [{$unit}]."),
        };
    }

    public static function ago(int $amount, string $unit): Carbon
    {
        return self::shift(now(), $amount, $unit, forward: false);
    }

    public static function ahead(int $amount, string $unit): Carbon
    {
        return self::shift(now(), $amount, $unit);
    }

    public static function describe(int $amount, string $unit): string
    {
        $label = match ($unit) {
            'minutes' => 'minute',
            'hours' => 'hour',
            'days' => 'day',
            'business_days' => 'business day',
            default => $unit,
        };

        return $amount.' '.$label.($amount === 1 ? '' : 's');
    }
}
