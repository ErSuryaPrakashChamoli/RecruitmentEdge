<?php

namespace App\Services\Metrics\Definitions;

use App\Enums\MetricCategory;
use App\Enums\MetricKind;
use App\Enums\MetricMaterialization;
use App\Enums\MetricScopeModel;
use App\Enums\MetricUnit;
use App\Enums\TargetMetric;
use App\Models\Employee;
use App\Services\Metrics\MetricDefinition;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricResult;
use App\Services\Metrics\MetricSpec;
use App\Services\RecruiterDailyMetricsService;

/**
 * recruiter.activity (D8, D32): what the team did — activity, never presented as an outcome.
 */
class RecruiterActivity extends MetricDefinition
{
    /**
     * @var array<int, TargetMetric>
     */
    public const array ACTIVITY_METRICS = [
        TargetMetric::ProfilesSourced,
        TargetMetric::Calls,
        TargetMetric::ConnectedCalls,
        TargetMetric::InterestedCandidates,
        TargetMetric::Screening,
        TargetMetric::Shortlisted,
    ];

    public function spec(): MetricSpec
    {
        return new MetricSpec(
            key: 'recruiter.activity',
            version: 1,
            effectiveFrom: '2026-09-27',
            name: 'Recruiter Activity',
            description: 'Recruiting activity performed in the period: profiles sourced, calls, connected calls and candidates moved to Interested, Screened and Shortlisted.',
            category: MetricCategory::Activity,
            kind: MetricKind::OperationalActivity,
            purpose: 'How much recruiting work the team did.',
            population: 'Candidates created, logged calls and genuine stage entries in the period, by the people in scope.',
            numerator: 'Per activity: count (stage moves: distinct applications moved).',
            denominator: null,
            statistic: 'count per activity; headline = profiles sourced + calls + stage moves',
            anchor: 'candidates.created_at; recruitment_daily_activities.activity_datetime; candidate_stage_histories.created_at',
            endEvent: null,
            dateSemantics: 'Instants within the period in the business timezone.',
            scope: MetricScopeModel::Actor,
            attribution: 'The person who did it (created_by / the activity\'s recruiter / changed_by).',
            filters: ['recruiter_id'],
            exclusions: 'Free-form manual activity logs are never read; status changes are not stage moves (DF-9).',
            unknownRule: 'Not applicable.',
            unobservedRule: 'Not applicable.',
            invalidRule: 'Activities logged outside the Phase 8.5 logging rules (SEC-4) cannot be created.',
            unit: MetricUnit::Count,
            source: 'candidates, recruitment_daily_activities, candidate_stage_histories',
            materialization: MetricMaterialization::Cached,
            reproducibility: 'Reproducible from the fact tables.',
            minSample: 0,
        );
    }

    protected function evaluate(MetricQuery $query): MetricResult
    {
        $period = $query->requirePeriod();
        $visible = $this->scope->visibleEmployeeIds($query->viewer);
        $recruiter = $query->filter('recruiter_id');
        $ids = match (true) {
            $recruiter !== null => ($visible === null || $visible->contains($recruiter)) ? collect([$recruiter]) : collect(),
            $visible !== null => $visible,
            default => Employee::query()->pluck('id'),
        };

        $metrics = app(RecruiterDailyMetricsService::class);
        $byMetric = collect(self::ACTIVITY_METRICS)->mapWithKeys(fn (TargetMetric $metric) => [
            $metric->value => array_sum($metrics->actualsFor($ids, $metric, $period)),
        ]);

        $total = $byMetric->except([TargetMetric::ConnectedCalls->value])->sum();

        return $this->result($query, (float) $total, (int) $total, ['by_metric' => $byMetric->all(), 'people' => $ids->count()]);
    }
}
