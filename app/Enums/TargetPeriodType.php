<?php

namespace App\Enums;

/**
 * The period a configured target value covers. See TargetResolutionService::resolveForRange()
 * for how a target of one period is applied to (or prorated over) a range of another length.
 */
enum TargetPeriodType: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Daily',
            self::Weekly => 'Weekly',
            self::Monthly => 'Monthly',
        };
    }
}
