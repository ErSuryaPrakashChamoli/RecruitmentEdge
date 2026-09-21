<?php

namespace App\Enums;

/**
 * What reaching a higher slab mid-month means for a slab-based incentive rule.
 * Incremental: each occurrence keeps the rate of the band it fell in. Retroactive: the month's other
 * live calculations of the rule are re-priced at the new band (see RecruiterIncentiveCalculator).
 */
enum IncentiveSlabUpgradeMode: string
{
    case Incremental = 'incremental';
    case Retroactive = 'retroactive';

    public function label(): string
    {
        return match ($this) {
            self::Incremental => 'Only new occurrences',
            self::Retroactive => 'All occurrences in the month',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Incremental => 'Each occurrence keeps the rate of the slab it fell in; only later ones get the higher rate.',
            self::Retroactive => 'Reaching a higher slab re-prices every occurrence that month. Pending calculations are updated; approved or paid ones get a top-up adjustment.',
        };
    }
}
