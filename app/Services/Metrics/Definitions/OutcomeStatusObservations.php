<?php

namespace App\Services\Metrics\Definitions;

use App\Enums\MetricCategory;
use App\Enums\MetricKind;
use App\Enums\MetricMaterialization;
use App\Enums\MetricScopeModel;
use App\Enums\MetricUnit;
use App\Services\Metrics\MetricSpec;

/**
 * outcome.status_observations — Outcome Loop™ (Phase 8.2), governed in Phase 8.5.
 */
class OutcomeStatusObservations extends OutcomeMetric
{
    public function spec(): MetricSpec
    {
        return new MetricSpec(
            key: 'outcome.status_observations',
            version: 1,
            effectiveFrom: '2026-09-27',
            name: 'Retention Observations (30 / 90 / 180 days)',
            description: 'The employee status observed at 30, 90 and 180 days after joining, or a separation recorded before the checkpoint. Observed active is not confirmed retention; the 90- and 180-day rates are among hires still observed at the earlier checkpoint.',
            category: MetricCategory::Retention,
            kind: MetricKind::BusinessOutcome,
            purpose: 'What actually happened after the hiring decision, from recorded outcomes.',
            population: 'Completed joins with a joining date in the period.',
            numerator: 'Observed active, per checkpoint.',
            denominator: 'Observed active + inactive + separated before the checkpoint.',
            statistic: 'rate per checkpoint',
            anchor: 'hiring_outcomes.observed_at / hiring_outcome_snapshots.joined_on',
            endEvent: null,
            dateSemantics: 'Business-timezone calendar days, inclusive.',
            scope: MetricScopeModel::RequisitionInvolvement,
            attribution: 'The requisition; source = the source frozen at the hire.',
            filters: ['department_id', 'designation_id', 'location_id', 'requisition_id', 'source_id'],
            exclusions: 'Voided outcomes.',
            unknownRule: 'Unknown and not-observed records are reported next to the value, never counted as failures.',
            unobservedRule: 'Retention is observed going forward only; checkpoints before observation started are not observed.',
            invalidRule: 'Invalid source data (a start after the join) is unknown.',
            unit: MetricUnit::Percent,
            source: 'hiring_outcomes, hiring_outcome_snapshots (OutcomeAnalyticsService)',
            materialization: MetricMaterialization::Snapshot,
            reproducibility: 'Frozen per hire; outcome records are versioned and never rewritten.',
        );
    }

    protected function section(): string
    {
        return 'status_observations';
    }

    protected function hasHeadline(): bool
    {
        return false;
    }
}
