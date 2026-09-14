<?php

namespace App\Enums;

/**
 * Whether a recruiter directly controls a target metric through their own effort (sourcing,
 * calling, screening, lining up interviews) or only influences it, since the outcome also depends
 * on hiring managers, clients, and candidates (selections, offers, joining).
 */
enum MetricAccountability: string
{
    case Controllable = 'controllable';
    case Influenced = 'influenced';

    public function label(): string
    {
        return match ($this) {
            self::Controllable => 'Controllable',
            self::Influenced => 'Influenced',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Controllable => 'Driven directly by the recruiter\'s own effort.',
            self::Influenced => 'Shaped by the recruiter but decided by hiring managers, clients, or candidates.',
        };
    }
}
