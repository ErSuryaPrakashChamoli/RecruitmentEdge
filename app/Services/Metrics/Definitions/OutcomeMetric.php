<?php

namespace App\Services\Metrics\Definitions;

use App\Enums\MetricResultStatus;
use App\Services\Metrics\MetricDefinition;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricResult;
use App\Services\Outcomes\OutcomeAnalyticsService;

/**
 * The Outcome Loop metrics (Phase 8.2) registered as governed metrics: one implementation
 * (OutcomeAnalyticsService), one result shape. Scope is requisition involvement, as in 8.2.
 */
abstract class OutcomeMetric extends MetricDefinition
{
    /**
     * The OutcomeAnalyticsService report section this metric reads.
     */
    abstract protected function section(): string;

    protected function evaluate(MetricQuery $query): MetricResult
    {
        $period = $query->requirePeriod();
        $section = app(OutcomeAnalyticsService::class)->section($this->section(), $query->viewer, [
            ...$query->filters,
            'from' => $period->fromDate(),
            'to' => $period->toDate(),
        ]);

        $sample = (int) $section['sample_size'];
        $value = $section['value'] !== null ? (float) $section['value'] : null;
        $details = collect($section)->except(['label', 'definition', 'population', 'sample_size', 'value', 'unit', 'unknown', 'unknown_handling', 'band'])
            ->map(fn ($item) => $item instanceof \BackedEnum ? $item->value : $item)
            ->all();

        // A breakdown section (no headline value) with data is available as a breakdown.
        $status = $value === null && $sample > 0 && ! $this->hasHeadline() ? MetricResultStatus::Ok : null;

        return $this->result($query, $value, $sample, [...$details, 'band' => $section['band']->value, 'label' => $section['label']], unknown: (int) $section['unknown'], status: $status);
    }

    /**
     * Whether the section carries a headline value (rates, time to hire) or only a breakdown.
     */
    protected function hasHeadline(): bool
    {
        return true;
    }
}
