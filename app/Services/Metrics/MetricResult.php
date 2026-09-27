<?php

namespace App\Services\Metrics;

use App\Enums\MetricResultStatus;
use App\Enums\MetricUnit;
use Carbon\CarbonImmutable;

/**
 * The one shape every consumer of a governed metric receives (Phase 8.5 §29) — dashboard, report,
 * export and Copilot alike. A withheld value is null with a status that says why; it is never 0.
 */
final readonly class MetricResult
{
    /**
     * @param  array<string, mixed>  $details  numerator/denominator and metric-specific breakdowns
     */
    public function __construct(
        public string $key,
        public int $version,
        public MetricResultStatus $status,
        public ?float $value,
        public MetricUnit $unit,
        public int $sampleSize,
        public int $unknownCount,
        public int $excludedCount,
        public ?array $period,
        public string $scopeBasis,
        public CarbonImmutable $asOf,
        public array $details = [],
    ) {}

    public function isAvailable(): bool
    {
        return $this->status === MetricResultStatus::Ok && $this->value !== null;
    }

    public function display(): string
    {
        return $this->isAvailable() ? $this->unit->format((float) $this->value) : $this->status->placeholder();
    }

    /**
     * One line saying what the number rests on, for a tooltip or a Copilot answer.
     */
    public function basisLine(): string
    {
        $parts = ["n = {$this->sampleSize}"];

        if ($this->unknownCount > 0) {
            $parts[] = "{$this->unknownCount} unknown";
        }

        if ($this->excludedCount > 0) {
            $parts[] = "{$this->excludedCount} excluded";
        }

        if ($this->status !== MetricResultStatus::Ok) {
            $parts[] = strtolower($this->status->label());
        }

        return implode(' · ', $parts)." · {$this->key} v{$this->version}";
    }

    public function detail(string $key, mixed $default = null): mixed
    {
        return data_get($this->details, $key, $default);
    }

    /**
     * Plain data for the cache: the application never lets the cache unserialize objects
     * (config cache.serializable_classes = false), so a result is stored as an array.
     *
     * @return array<string, mixed>
     */
    public function toCache(): array
    {
        return [
            'key' => $this->key,
            'version' => $this->version,
            'status' => $this->status->value,
            'value' => $this->value,
            'unit' => $this->unit->value,
            'sample_size' => $this->sampleSize,
            'unknown_count' => $this->unknownCount,
            'excluded_count' => $this->excludedCount,
            'period' => $this->period,
            'scope' => $this->scopeBasis,
            'as_of' => $this->asOf->toIso8601String(),
            'details' => $this->details,
        ];
    }

    /**
     * @param  array<string, mixed>  $cached
     */
    public static function fromCache(array $cached): self
    {
        return new self(
            key: $cached['key'],
            version: (int) $cached['version'],
            status: MetricResultStatus::from($cached['status']),
            value: $cached['value'] !== null ? (float) $cached['value'] : null,
            unit: MetricUnit::from($cached['unit']),
            sampleSize: (int) $cached['sample_size'],
            unknownCount: (int) $cached['unknown_count'],
            excludedCount: (int) $cached['excluded_count'],
            period: $cached['period'],
            scopeBasis: $cached['scope'],
            asOf: CarbonImmutable::parse($cached['as_of']),
            details: $cached['details'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'metric' => $this->key,
            'version' => $this->version,
            'status' => $this->status->value,
            'value' => $this->value,
            'unit' => $this->unit->value,
            'display' => $this->display(),
            'sample_size' => $this->sampleSize,
            'unknown_count' => $this->unknownCount,
            'excluded_count' => $this->excludedCount,
            'period' => $this->period,
            'scope' => $this->scopeBasis,
            'as_of' => $this->asOf->toIso8601String(),
            'details' => $this->details,
        ];
    }
}
