<?php

namespace App\Enums;

/**
 * How a governed metric is produced (Phase 8.5 D11–D15).
 */
enum MetricMaterialization: string
{
    case QueryTime = 'query_time';
    case Cached = 'cached';
    case Snapshot = 'snapshot';
    case EventDriven = 'event_driven';
    case Hybrid = 'hybrid';

    public function label(): string
    {
        return match ($this) {
            self::QueryTime => 'Computed on request',
            self::Cached => 'Computed on request, cached briefly',
            self::Snapshot => 'Read from frozen snapshots',
            self::EventDriven => 'Maintained from events',
            self::Hybrid => 'Snapshot where frozen, computed otherwise',
        };
    }
}
