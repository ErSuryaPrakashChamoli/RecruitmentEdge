<?php

namespace App\Services\AI\Tools\Concerns;

use App\Services\Metrics\MetricPeriod;
use Carbon\CarbonImmutable;

/**
 * Phase 8.5 (D17, DF-5): a Copilot tool's start_date/end_date arguments are inclusive calendar dates
 * in the business timezone — an end date covers that whole day — exactly like the dashboard period.
 */
trait ResolvesMetricPeriod
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function metricPeriod(array $arguments, int $defaultDays): MetricPeriod
    {
        $to = filled($arguments['end_date'] ?? null) ? CarbonImmutable::parse((string) $arguments['end_date'])->toDateString() : MetricPeriod::now()->toDateString();
        $from = filled($arguments['start_date'] ?? null)
            ? CarbonImmutable::parse((string) $arguments['start_date'])->toDateString()
            : CarbonImmutable::parse($to)->subDays(max(1, $defaultDays) - 1)->toDateString();

        return $from > $to ? MetricPeriod::dates($to, $from) : MetricPeriod::dates($from, $to);
    }
}
