<?php

namespace App\Services\Metrics;

use App\Enums\MetricCategory;
use App\Enums\MetricKind;
use App\Enums\MetricMaterialization;
use App\Enums\MetricScopeModel;
use App\Enums\MetricUnit;

/**
 * The governed definition of one metric version (Phase 8.5 §29): everything a reader needs to know
 * what the number means, written down once and shown wherever the number is shown.
 *
 * Semantic fields are fingerprinted (fingerprint()); MetricRegistryTest fails when they change
 * without a version bump, so a definition can never silently change meaning (D30).
 */
final readonly class MetricSpec
{
    /**
     * @param  array<int, string>  $filters  the filter keys the metric honours (see MetricQuery)
     * @param  int|null  $minSample  null = config metrics.min_sample; 0 = the metric has no sample rule
     */
    public function __construct(
        public string $key,
        public int $version,
        public string $effectiveFrom,
        public string $name,
        public string $description,
        public MetricCategory $category,
        public MetricKind $kind,
        public string $purpose,
        public string $population,
        public string $numerator,
        public ?string $denominator,
        public string $statistic,
        public string $anchor,
        public ?string $endEvent,
        public string $dateSemantics,
        public MetricScopeModel $scope,
        public string $attribution,
        public array $filters,
        public string $exclusions,
        public string $unknownRule,
        public string $unobservedRule,
        public string $invalidRule,
        public MetricUnit $unit,
        public string $source,
        public MetricMaterialization $materialization,
        public string $reproducibility,
        public ?int $minSample = null,
        public ?string $effectiveTo = null,
        public string $owner = 'Recruitment product owner',
        public string $privacy = 'aggregate',
        public string $status = 'active',
        public string $asOf = 'computed_at',
        public bool $cacheable = true,
        public ?string $supersedes = null,
    ) {}

    public function minimumSample(): int
    {
        return $this->minSample ?? (int) config('metrics.min_sample', 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'version' => $this->version,
            'effective_from' => $this->effectiveFrom,
            'effective_to' => $this->effectiveTo,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category->value,
            'kind' => $this->kind->value,
            'purpose' => $this->purpose,
            'population' => $this->population,
            'numerator' => $this->numerator,
            'denominator' => $this->denominator,
            'statistic' => $this->statistic,
            'anchor' => $this->anchor,
            'end_event' => $this->endEvent,
            'date_semantics' => $this->dateSemantics,
            'timezone' => MetricPeriod::timezone(),
            'scope' => $this->scope->value,
            'attribution' => $this->attribution,
            'filters' => $this->filters,
            'exclusions' => $this->exclusions,
            'unknown_rule' => $this->unknownRule,
            'unobserved_rule' => $this->unobservedRule,
            'invalid_rule' => $this->invalidRule,
            'min_sample' => $this->minimumSample(),
            'unit' => $this->unit->value,
            'rounding' => $this->unit->decimals(),
            'source' => $this->source,
            'owner' => $this->owner,
            'privacy' => $this->privacy,
            'materialization' => $this->materialization->value,
            'cacheable' => $this->cacheable,
            'reproducibility' => $this->reproducibility,
            'status' => $this->status,
            'as_of' => $this->asOf,
            'supersedes' => $this->supersedes,
        ];
    }

    /**
     * A hash of the fields that carry meaning. Presentation, ownership and lifecycle fields are left
     * out: changing a description is not a new version; changing a denominator is.
     */
    public function fingerprint(): string
    {
        $semantic = collect($this->toArray())->only([
            'key', 'population', 'numerator', 'denominator', 'statistic', 'anchor', 'end_event', 'date_semantics',
            'scope', 'attribution', 'filters', 'exclusions', 'unknown_rule', 'unobserved_rule', 'invalid_rule', 'unit',
            'source',
        ])->all();
        $semantic['min_sample_rule'] = $this->minSample ?? 'config';

        return substr(sha1(json_encode($semantic, JSON_THROW_ON_ERROR)), 0, 12);
    }
}
