<?php

namespace App\Services\Metrics\Definitions;

use App\Enums\MetricCategory;
use App\Enums\MetricKind;
use App\Enums\MetricMaterialization;
use App\Enums\MetricScopeModel;
use App\Enums\MetricUnit;
use App\Services\Metrics\Concerns\QueriesJoiningOutcomes;
use App\Services\Metrics\MetricDefinition;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricResult;
use App\Services\Metrics\MetricSpec;

/**
 * joining.dropout_rate (D5, D41): the Outcome Loop joining definition, read from the joining records.
 */
class JoiningDropoutRate extends MetricDefinition
{
    use QueriesJoiningOutcomes;

    public function spec(): MetricSpec
    {
        return new MetricSpec(
            key: 'joining.dropout_rate',
            version: 1,
            effectiveFrom: '2026-09-27',
            name: 'Joining Dropout Rate',
            description: 'Share of joining outcomes that were a dropout before joining: dropout ÷ (joined + no-show + dropout).',
            category: MetricCategory::Joining,
            kind: MetricKind::BusinessOutcome,
            purpose: 'How often a candidate withdraws between accepting and joining.',
            population: 'Joining records whose final status (Joined, No-show, Dropout) was set in the period.',
            numerator: 'Dropout joining records.',
            denominator: 'Joined + no-show + dropout joining records.',
            statistic: 'rate',
            anchor: 'Joined: actual_doj; No-show / Dropout: when the joining record last changed.',
            endEvent: null,
            dateSemantics: 'actual_doj (date) or the status-change instant within the period, business timezone.',
            scope: MetricScopeModel::ApplicationOwner,
            attribution: 'Current owner of the application.',
            filters: ['department_id', 'designation_id', 'location_id', 'requisition_id', 'source_id', 'recruiter_id'],
            exclusions: 'Cancelled joinings (an employer or rejection outcome, not a candidate decision) are excluded and counted; deleted applications.',
            unknownRule: 'Joinings still expected or confirmed with a joining date up to the period end are pending — reported, never counted as failures.',
            unobservedRule: 'Pending joinings are not yet observed.',
            invalidRule: 'A Joined record without an actual joining date is not dated and not counted.',
            unit: MetricUnit::Percent,
            source: 'candidate_joinings',
            materialization: MetricMaterialization::Cached,
            reproducibility: 'Reproducible from the joining records for a given as_of.',
            supersedes: 'RecruitmentAnalyticsService::joiningAnalytics joining_percent / expected-date no-show and dropout counts',
        );
    }

    protected function evaluate(MetricQuery $query): MetricResult
    {
        $counts = $this->joiningOutcomeCounts($query);

        return $this->result($query, $this->rate($counts['dropout'], $counts['decided']), $counts['decided'], $counts, unknown: $counts['pending'], excluded: $counts['cancelled']);
    }
}
