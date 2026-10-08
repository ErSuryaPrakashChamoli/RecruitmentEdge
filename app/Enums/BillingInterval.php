<?php

namespace App\Enums;

use Carbon\CarbonInterface;

/**
 * SaaS-4: the canonical billing intervals. A provider's interval is normalised to one of these.
 */
enum BillingInterval: string
{
    case Month = 'month';
    case Year = 'year';

    public function after(CarbonInterface $start): CarbonInterface
    {
        return match ($this) {
            self::Month => $start->copy()->addMonthNoOverflow(),
            self::Year => $start->copy()->addYearNoOverflow(),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Month => 'Monthly',
            self::Year => 'Yearly',
        };
    }
}
