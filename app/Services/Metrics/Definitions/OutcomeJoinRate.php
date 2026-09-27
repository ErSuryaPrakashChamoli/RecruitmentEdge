<?php

namespace App\Services\Metrics\Definitions;

use App\Enums\MetricCategory;
use App\Enums\MetricKind;
use App\Enums\MetricMaterialization;
use App\Enums\MetricScopeModel;
use App\Enums\MetricUnit;
use App\Services\Metrics\MetricSpec;

/**
 * outcome.join_rate — Outcome Loop™ (Phase 8.2), governed in Phase 8.5.
 */
class OutcomeJoinRate extends OutcomeMetric
{
    public function spec(): MetricSpec
    {
        return new MetricSpec(
            key: 'outcome.join_rate',
            version: 1,
            effectiveFrom: '2026-09-27',
            name: 'Joining Outcomes',
            description: 'Joined ÷ (joined + no-show + dropout) from the joining record, as recorded by the Outcome Loop.',
            category: MetricCategory::Joining,
            kind: MetricKind::BusinessOutcome,
            purpose: 'What actually happened after the hiring decision, from recorded outcomes.',
            population: 'Joining outcomes recorded in the period.',
            numerator: 'Joined outcomes.',
            denominator: 'Joined + no-show + dropout outcomes.',
            statistic: 'rate',
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
        return 'joining';
    }
}
