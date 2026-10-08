<?php

namespace App\Services\Metrics;

use App\Enums\MetricResultStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * One governed metric (Phase 8.5). A definition declares its spec and computes a MetricResult; the
 * shared rules — refusing undeclared filters, the minimum sample, rounding, provenance — are applied
 * here so no definition can skip them.
 */
abstract class MetricDefinition
{
    public function __construct(protected readonly MetricScope $scope) {}

    abstract public function spec(): MetricSpec;

    /**
     * Compute the raw result. Implementations return value and sample size; this class applies the
     * sample rule and rounding.
     */
    abstract protected function evaluate(MetricQuery $query): MetricResult;

    public function key(): string
    {
        return $this->spec()->key;
    }

    public function compute(MetricQuery $query): MetricResult
    {
        $spec = $this->spec();

        foreach (array_keys($query->filters) as $filter) {
            if (! in_array($filter, $spec->filters, true)) {
                throw new InvalidArgumentException("Metric [{$spec->key}] does not support the [{$filter}] filter.");
            }
        }

        $result = $this->evaluate($query);

        if ($result->status === MetricResultStatus::Ok && $result->sampleSize < $spec->minimumSample()) {
            return $this->withStatus($result, MetricResultStatus::InsufficientSample);
        }

        return $result;
    }

    /**
     * Build a result for this metric. A null value with a zero sample is NoData; a null value with a
     * population but nothing measurable is Unknown.
     *
     * @param  array<string, mixed>  $details
     */
    protected function result(MetricQuery $query, ?float $value, int $sampleSize, array $details = [], int $unknown = 0, int $excluded = 0, ?MetricResultStatus $status = null): MetricResult
    {
        $spec = $this->spec();
        $status ??= match (true) {
            $value !== null => MetricResultStatus::Ok,
            $sampleSize === 0 && $unknown === 0 => MetricResultStatus::NoData,
            $sampleSize > 0 && $sampleSize < $spec->minimumSample() => MetricResultStatus::InsufficientSample,
            default => MetricResultStatus::Unknown,
        };

        return new MetricResult(
            key: $spec->key,
            version: $spec->version,
            status: $status,
            value: $value !== null && $status === MetricResultStatus::Ok ? $spec->unit->round($value) : null,
            unit: $spec->unit,
            sampleSize: $sampleSize,
            unknownCount: $unknown,
            excludedCount: $excluded,
            period: $query->period?->toArray(),
            scopeBasis: $this->scope->basis($query->viewer).($query->filter('recruiter_id') !== null ? ':recruiter:'.$query->filter('recruiter_id') : ''),
            asOf: CarbonImmutable::now(),
            // Plain data only (enums as their values): the same shape whether computed or cached.
            details: json_decode(json_encode($details, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    /**
     * A rate as a percentage, or null when the denominator is empty.
     */
    protected function rate(int $numerator, int $denominator): ?float
    {
        return $denominator > 0 ? $numerator / $denominator * 100 : null;
    }

    /**
     * @param  Collection<int, int|float>  $values
     */
    protected function median(Collection $values): ?float
    {
        return $values->isEmpty() ? null : (float) $values->median();
    }

    /**
     * Phase 8.9 (P89-PERF-028/029): median() for a plain list that may hold millions of values,
     * without Collection's filtered, sorted and re-indexed copies. Sorts $values in place (pass a
     * list you no longer need in insertion order) and returns exactly what median() returns.
     *
     * @param  list<int|float>  $values
     */
    protected function medianSortingInPlace(array &$values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);

        return $this->medianOfSortedLists([$values]);
    }

    /**
     * The median of several already sorted lists taken together — what median() returns for their
     * union (an even count averages the two middle values) — found by walking the lists in order,
     * without merging them into one more copy.
     *
     * @param  array<array-key, list<int|float>>  $sortedLists
     */
    protected function medianOfSortedLists(array $sortedLists): ?float
    {
        $count = array_sum(array_map('count', $sortedLists));

        if ($count === 0) {
            return null;
        }

        $middle = intdiv($count, 2);
        $positions = array_map(fn (): int => 0, $sortedLists);
        $lengths = array_map('count', $sortedLists);
        $previous = null;

        for ($rank = 0; $rank <= $middle; $rank++) {
            $smallest = null;

            foreach ($sortedLists as $key => $list) {
                if ($positions[$key] < $lengths[$key] && ($smallest === null || $list[$positions[$key]] < $sortedLists[$smallest][$positions[$smallest]])) {
                    $smallest = $key;
                }
            }

            $value = $sortedLists[$smallest][$positions[$smallest]++];

            if ($rank === $middle) {
                return $count % 2 ? (float) $value : (float) ((0 + $previous + $value) / 2);
            }

            $previous = $value;
        }

        return null;
    }

    /**
     * Withhold a sub-value (a breakdown row) under the same sample rule as the headline.
     */
    protected function withheld(?float $value, int $sampleSize): ?float
    {
        return $value !== null && $sampleSize >= $this->spec()->minimumSample() ? $this->spec()->unit->round($value) : null;
    }

    private function withStatus(MetricResult $result, MetricResultStatus $status): MetricResult
    {
        return new MetricResult(
            key: $result->key,
            version: $result->version,
            status: $status,
            value: null,
            unit: $result->unit,
            sampleSize: $result->sampleSize,
            unknownCount: $result->unknownCount,
            excludedCount: $result->excludedCount,
            period: $result->period,
            scopeBasis: $result->scopeBasis,
            asOf: $result->asOf,
            details: $result->details,
        );
    }
}
