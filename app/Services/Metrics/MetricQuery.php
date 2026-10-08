<?php

namespace App\Services\Metrics;

use App\Models\User;
use InvalidArgumentException;

/**
 * What a governed metric is asked for: a period, the viewer whose hierarchy scope applies (null =
 * the whole organization, for system jobs only), and filters. Filters are typed ids; a metric
 * refuses a filter it does not declare rather than silently ignoring it.
 */
final readonly class MetricQuery
{
    /**
     * recruiter_id narrows an application-owner metric to applications owned by exactly that
     * recruiter (who must be visible to the viewer); the others narrow by requisition attributes or
     * the hire's source.
     */
    public const array FILTERS = ['department_id', 'requisition_id', 'source_id', 'designation_id', 'location_id', 'recruiter_id'];

    /**
     * @param  array<string, int>  $filters
     */
    public function __construct(
        public ?MetricPeriod $period,
        public ?User $viewer,
        public array $filters = [],
    ) {
        foreach (array_keys($filters) as $filter) {
            if (! in_array($filter, self::FILTERS, true)) {
                throw new InvalidArgumentException("Unknown metric filter [{$filter}].");
            }
        }
    }

    /**
     * @param  array<string, int|string|null>  $filters  null / empty values are dropped
     */
    public static function make(?MetricPeriod $period, ?User $viewer, array $filters = []): self
    {
        return new self($period, $viewer, collect($filters)->filter(fn ($value) => filled($value))->map(fn ($value) => (int) $value)->all());
    }

    public function filter(string $key): ?int
    {
        return $this->filters[$key] ?? null;
    }

    public function without(string ...$filters): self
    {
        return new self($this->period, $this->viewer, collect($this->filters)->except($filters)->all());
    }

    /**
     * @param  array<string, int>  $filters
     */
    public function with(array $filters): self
    {
        return new self($this->period, $this->viewer, [...$this->filters, ...$filters]);
    }

    public function withPeriod(MetricPeriod $period): self
    {
        return new self($period, $this->viewer, $this->filters);
    }

    public function requirePeriod(): MetricPeriod
    {
        return $this->period ?? throw new InvalidArgumentException('This metric needs a period.');
    }
}
