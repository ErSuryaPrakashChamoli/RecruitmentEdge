<?php

namespace App\Enums;

/**
 * The Phase 8.5 metric taxonomy (docs/phase-8-5-discovery.md §30).
 */
enum MetricCategory: string
{
    case Pipeline = 'pipeline';
    case Activity = 'activity';
    case Conversion = 'conversion';
    case Offer = 'offer';
    case Joining = 'joining';
    case Time = 'time';
    case Source = 'source';
    case Quality = 'quality';
    case Retention = 'retention';
    case Sla = 'sla';
    case Cost = 'cost';
    case Productivity = 'productivity';
    case Outcome = 'outcome';
    case Intelligence = 'intelligence';
    case Operational = 'operational';

    public function label(): string
    {
        return match ($this) {
            self::Sla => 'SLA',
            self::Productivity => 'Workforce / productivity',
            self::Intelligence => 'AI-derived / intelligence',
            default => ucfirst($this->value),
        };
    }
}
