<?php

namespace App\Services\Metrics\Definitions;

use App\Enums\CandidateStage;
use App\Enums\JoiningStatus;
use App\Enums\MetricCategory;
use App\Enums\MetricKind;
use App\Enums\MetricMaterialization;
use App\Enums\MetricScopeModel;
use App\Enums\MetricUnit;
use App\Models\CandidateApplication;
use App\Models\CandidateStageHistory;
use App\Services\Metrics\MetricDefinition;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricResult;
use App\Services\Metrics\MetricSpec;
use Illuminate\Support\Facades\DB;

/**
 * pipeline.funnel (Funnel decision): a cohort funnel — of the applications created in the period,
 * how many have reached each stage by now. A cohort percentage can never exceed 100%.
 */
class FunnelConversion extends MetricDefinition
{
    public function spec(): MetricSpec
    {
        return new MetricSpec(
            key: 'pipeline.funnel',
            version: 1,
            effectiveFrom: '2026-09-27',
            name: 'Funnel Conversion',
            description: 'Of the applications created in the period, the share that has reached each stage so far; the headline is the share that has joined.',
            category: MetricCategory::Conversion,
            kind: MetricKind::BusinessOutcome,
            purpose: 'Where the applications of a period drop out.',
            population: 'Applications with an application date in the period (the cohort).',
            numerator: 'Cohort applications that have reached the stage or any later stage by now (genuine stage entries or the stage they were created at); Joined = a joining record marked Joined.',
            denominator: 'Cohort size.',
            statistic: 'rate',
            anchor: 'candidate_applications.application_date',
            endEvent: 'Stage reached (any time up to now); Joined from the joining record.',
            dateSemantics: 'application_date within the period, inclusive; stage progress as of now.',
            scope: MetricScopeModel::ApplicationOwner,
            attribution: 'Current owner of the application.',
            filters: ['department_id', 'designation_id', 'location_id', 'requisition_id', 'source_id', 'recruiter_id'],
            exclusions: 'Status changes and requisition moves are not stage entries (DF-9); deleted applications.',
            unknownRule: 'Not applicable.',
            unobservedRule: 'A recent cohort is still moving: its percentages rise over time (as_of = computed_at).',
            invalidRule: 'Not applicable.',
            unit: MetricUnit::Percent,
            source: 'candidate_applications, candidate_stage_histories, candidate_joinings',
            materialization: MetricMaterialization::Cached,
            reproducibility: 'Changes as the cohort progresses; reproducible for a given as_of.',
            minSample: 0,
            supersedes: 'RecruitmentAnalyticsService::funnel conversion_from_sourced (period events ÷ applications, could exceed 100%)',
        );
    }

    protected function evaluate(MetricQuery $query): MetricResult
    {
        // One grouped query: for each cohort application, the furthest stage reached (its creation
        // stage or its furthest genuine stage entry) and whether it has joined; then a count per stage.
        $order = fn (string $column) => 'case '.$column.' '.collect(CandidateStage::cases())->map(fn (CandidateStage $stage) => "when '{$stage->value}' then {$stage->order()}")->implode(' ').' else 0 end';

        $cohort = $this->scope->applications(CandidateApplication::query(), $query)
            ->tap(fn ($q) => $query->requirePeriod()->whereDateColumn($q, 'candidate_applications.application_date'))
            ->leftJoin('candidate_stage_histories', function ($join): void {
                $join->on('candidate_stage_histories.candidate_application_id', '=', 'candidate_applications.id');
                CandidateStageHistory::constrainToMilestoneEntries($join);
            })
            ->groupBy('candidate_applications.id', 'candidate_applications.current_stage')
            ->selectRaw('candidate_applications.id as application_id, '.$order('candidate_applications.current_stage').' as created_order, max('.$order('candidate_stage_histories.new_stage').') as entry_order')
            ->toBase();

        $sums = collect(CandidateStage::cases())->map(fn (CandidateStage $stage) => "sum(case when (case when coalesce(reached.entry_order, 0) > reached.created_order then reached.entry_order else reached.created_order end) >= {$stage->order()} then 1 else 0 end) as reached_{$stage->value}");

        $row = DB::query()->fromSub($cohort, 'reached')
            ->selectRaw('count(*) as cohort, '.$sums->implode(', ').', sum(case when exists (select 1 from candidate_joinings where candidate_joinings.candidate_application_id = reached.application_id and candidate_joinings.status = ?) then 1 else 0 end) as joined', [JoiningStatus::Joined->value])
            ->first();

        $size = (int) ($row->cohort ?? 0);
        $joined = (int) ($row->joined ?? 0);

        $stages = collect(CandidateStage::cases())->map(function (CandidateStage $stage) use ($row, $size, $joined) {
            $count = match ($stage) {
                CandidateStage::Sourced => $size,
                CandidateStage::Joined => $joined,
                default => (int) ($row->{'reached_'.$stage->value} ?? 0),
            };

            return [
                'stage' => $stage->value,
                'label' => $stage->label(),
                'count' => $count,
                'percent_of_cohort' => $size > 0 ? round($count / $size * 100, 1) : null,
            ];
        })->all();

        return $this->result($query, $this->rate($joined, $size), $size, ['cohort' => $size, 'joined' => $joined, 'stages' => $stages]);
    }
}
