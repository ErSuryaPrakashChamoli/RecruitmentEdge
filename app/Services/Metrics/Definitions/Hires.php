<?php

namespace App\Services\Metrics\Definitions;

use App\Enums\MetricCategory;
use App\Enums\MetricKind;
use App\Enums\MetricMaterialization;
use App\Enums\MetricScopeModel;
use App\Enums\MetricUnit;
use App\Services\Metrics\Concerns\QueriesHires;
use App\Services\Metrics\MetricDefinition;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricResult;
use App\Services\Metrics\MetricSpec;

/**
 * hiring.hires (D33, D34): the one definition of "Joined" / "Hires" / "Positions filled in period".
 */
class Hires extends MetricDefinition
{
    use QueriesHires;

    public function spec(): MetricSpec
    {
        return new MetricSpec(
            key: 'hiring.hires',
            version: 1,
            effectiveFrom: '2026-09-27',
            name: 'Hires',
            description: 'People who joined in the period: joining records marked Joined, dated by the actual joining date.',
            category: MetricCategory::Joining,
            kind: MetricKind::BusinessOutcome,
            purpose: 'How many positions were filled.',
            population: 'Joining records marked Joined with an actual joining date in the period.',
            numerator: 'Count of those joining records.',
            denominator: null,
            statistic: 'count',
            anchor: 'candidate_joinings.actual_doj',
            endEvent: null,
            dateSemantics: 'actual_doj within the period, inclusive.',
            scope: MetricScopeModel::ApplicationOwner,
            attribution: 'Current owner of the application.',
            filters: ['department_id', 'designation_id', 'location_id', 'requisition_id', 'source_id', 'recruiter_id'],
            exclusions: 'The Joined pipeline stage alone never counts; deleted applications.',
            unknownRule: 'A Joined record without an actual joining date is not counted (reported as unknown).',
            unobservedRule: 'Not applicable.',
            invalidRule: 'Not applicable.',
            unit: MetricUnit::Count,
            source: 'candidate_joinings',
            materialization: MetricMaterialization::Cached,
            reproducibility: 'Reproducible from the joining records.',
            minSample: 0,
            supersedes: 'Funnel Joined stage count, Joining Control Center expected-date "Joined", Positions Filled (all time)',
        );
    }

    protected function evaluate(MetricQuery $query): MetricResult
    {
        $count = $this->hires($query)->count();

        return $this->result($query, (float) $count, $count);
    }
}
