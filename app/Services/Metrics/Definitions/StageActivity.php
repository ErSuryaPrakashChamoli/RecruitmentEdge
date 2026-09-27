<?php

namespace App\Services\Metrics\Definitions;

use App\Enums\CandidateStage;
use App\Enums\MetricCategory;
use App\Enums\MetricKind;
use App\Enums\MetricMaterialization;
use App\Enums\MetricScopeModel;
use App\Enums\MetricUnit;
use App\Models\CandidateApplication;
use App\Models\CandidateStageHistory;
use App\Services\Metrics\Concerns\QueriesHires;
use App\Services\Metrics\MetricDefinition;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricResult;
use App\Services\Metrics\MetricSpec;

/**
 * pipeline.stage_activity (Funnel decision): pipeline volumes in the period — how many
 * applications entered each stage. Volumes only; no percentages (a conversion is pipeline.funnel).
 */
class StageActivity extends MetricDefinition
{
    use QueriesHires;

    public function spec(): MetricSpec
    {
        return new MetricSpec(
            key: 'pipeline.stage_activity',
            version: 1,
            effectiveFrom: '2026-09-27',
            name: 'Stage Activity',
            description: 'Applications created in the period, and how many applications entered each stage in the period. Joined counts hires (joining records marked Joined).',
            category: MetricCategory::Pipeline,
            kind: MetricKind::OperationalActivity,
            purpose: 'How much pipeline movement happened in a period.',
            population: 'Genuine stage entries in the period; applications created in the period; hires in the period.',
            numerator: 'Per stage: distinct applications with a genuine entry into it in the period (Sourced: applications created; Joined: hires).',
            denominator: null,
            statistic: 'count per stage; headline = distinct applications that entered any stage',
            anchor: 'candidate_stage_histories.created_at (genuine entries); application_date for Sourced; actual_doj for Joined.',
            endEvent: null,
            dateSemantics: 'Entry instant within the period in the business timezone; dates inclusive.',
            scope: MetricScopeModel::ApplicationOwner,
            attribution: 'Current owner of the application.',
            filters: ['department_id', 'designation_id', 'location_id', 'requisition_id', 'source_id', 'recruiter_id'],
            exclusions: 'Status changes and requisition moves (DF-9); deleted applications.',
            unknownRule: 'Not applicable.',
            unobservedRule: 'Not applicable.',
            invalidRule: 'Not applicable.',
            unit: MetricUnit::Count,
            source: 'candidate_stage_histories, candidate_applications, candidate_joinings',
            materialization: MetricMaterialization::Cached,
            reproducibility: 'Reproducible from the immutable stage history.',
            minSample: 0,
            supersedes: 'RecruitmentAnalyticsService::funnel counts (included same-stage status rows)',
        );
    }

    protected function evaluate(MetricQuery $query): MetricResult
    {
        $period = $query->requirePeriod();
        $entries = fn () => $this->scope->throughApplication(CandidateStageHistory::query()->milestoneEntries(), $query)
            ->tap(fn ($q) => $period->whereTimestampColumn($q, 'candidate_stage_histories.created_at'));

        $byStage = $entries()->selectRaw('new_stage, count(distinct candidate_application_id) as total')->groupBy('new_stage')->pluck('total', 'new_stage');
        $created = $this->scope->applications(CandidateApplication::query(), $query)
            ->tap(fn ($q) => $period->whereDateColumn($q, 'candidate_applications.application_date'))
            ->count();
        $moved = $entries()->distinct()->count('candidate_application_id');

        $stages = collect(CandidateStage::cases())->map(fn (CandidateStage $stage) => [
            'stage' => $stage->value,
            'label' => $stage->label(),
            'count' => match ($stage) {
                CandidateStage::Sourced => $created,
                CandidateStage::Joined => $this->hires($query)->count(),
                default => (int) ($byStage[$stage->value] ?? 0),
            },
        ])->all();

        return $this->result($query, (float) $moved, $moved, ['stages' => $stages, 'applications_created' => $created]);
    }
}
