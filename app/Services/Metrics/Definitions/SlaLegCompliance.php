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
use App\Services\Metrics\MetricPeriod;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricResult;
use App\Services\Metrics\MetricSpec;
use App\Services\RecruitmentSettingService;
use App\Services\RecruitmentSlaService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * sla.leg_compliance (SLA decision): the share of completed pipeline legs that finished within
 * their SLA target — higher is better — from genuine stage entries only.
 *
 * v2 (Phase 8.6, D8.6-012): each leg is judged against the target that was in force when it
 * completed (RecruitmentSettingService::valueAt), so changing a target never rewrites past
 * compliance. v1 read the current target for every period.
 */
class SlaLegCompliance extends MetricDefinition
{
    use QueriesHires;

    public function spec(): MetricSpec
    {
        return new MetricSpec(
            key: 'sla.leg_compliance',
            version: 2,
            effectiveFrom: '2026-09-27',
            name: 'SLA Compliance',
            description: 'Share of pipeline legs completed in the period within their SLA target (higher is better), with the average and median duration of each leg.',
            category: MetricCategory::Sla,
            kind: MetricKind::Efficiency,
            purpose: 'Whether the team moves candidates within the agreed turnaround times.',
            population: 'Applications that completed a leg (entered its end stage) in the period; Selection → Joining ends at the actual joining date.',
            numerator: 'Legs completed within the target days.',
            denominator: 'Legs completed.',
            statistic: 'rate; per leg: average and median days',
            anchor: 'The latest genuine entry into the leg\'s start stage before its end (application creation for the first leg).',
            endEvent: 'The genuine entry into the leg\'s end stage; the joining record\'s actual joining date for Selection → Joining.',
            dateSemantics: 'End instant within the period in the business timezone; durations in fractional days.',
            scope: MetricScopeModel::ApplicationOwner,
            attribution: 'Current owner of the application.',
            filters: ['department_id', 'designation_id', 'location_id', 'requisition_id', 'source_id', 'recruiter_id'],
            exclusions: 'Legs whose start stage was skipped (no start entry) are excluded and counted; status changes are not stage entries (DF-9); deleted applications.',
            unknownRule: 'Not applicable.',
            unobservedRule: 'Legs still in progress are not in the population (see the open-breach alerts).',
            invalidRule: 'Not possible: stage entries are forward-only.',
            unit: MetricUnit::Percent,
            source: 'candidate_stage_histories, candidate_applications, candidate_joinings, recruitment_setting_changes (targets as of each leg\'s end)',
            materialization: MetricMaterialization::Cached,
            reproducibility: 'Reproducible from the stage history and the setting history: each leg uses the target in force at its end instant. Before the first recorded change of a target, the value it replaced is used; changes made before Phase 8.6 are not reconstructed.',
            supersedes: 'RecruitmentSlaService::stageTat sla_percent (average ÷ target, higher = worse)',
        );
    }

    protected function evaluate(MetricQuery $query): MetricResult
    {
        $settings = app(RecruitmentSettingService::class);
        $periodEnd = CarbonImmutable::createFromTimestamp(min($query->requirePeriod()->lastInstant()->getTimestamp(), now()->getTimestamp()));

        $legs = collect(RecruitmentSlaService::LEGS)->map(function (array $leg) use ($query, $settings, $periodEnd) {
            $targetAt = fn (int $end): int => (int) $settings->valueAtTimestamp($leg['setting_key'], $end, $leg['default_days']);
            ['durations' => $durations, 'within' => $within, 'applied' => $applied, 'skipped' => $skipped] = $this->legStatistics($leg['from'], $leg['to'], $query, $targetAt);
            ksort($applied);
            // Phase 8.9 (P89-PERF-028): plain-list mean (summed in collected order, as avg() does)
            // and an in-place median — no Collection copies of every leg. Same figures.
            $measured = count($durations);
            $reported = $measured > 0 && $measured >= $this->spec()->minimumSample();
            $average = $reported ? array_sum($durations) / $measured : null;
            $median = $reported ? $this->medianSortingInPlace($durations) : null;

            return [
                'label' => $leg['label'],
                'target_days' => $targetAt($periodEnd->getTimestamp()),
                'targets_applied' => array_keys($applied),
                'measured' => $measured,
                'within_target' => $within,
                'breaches' => $measured - $within,
                'skipped_start' => $skipped,
                'compliance_percent' => $this->withheld($this->rate($within, $measured), $measured),
                'average_days' => $average !== null ? MetricUnit::Days->round((float) $average) : null,
                'median_days' => $median !== null ? MetricUnit::Days->round($median) : null,
            ];
        });

        $measured = (int) $legs->sum('measured');

        return $this->result($query, $this->rate((int) $legs->sum('within_target'), $measured), $measured, ['legs' => $legs->all()], excluded: (int) $legs->sum('skipped_start'));
    }

    /**
     * One leg's figures over the legs completed in the period: each leg's duration in days (end
     * instant − latest start entry at or before it), whether it was within the target in force at
     * its end, the targets applied, and how many legs were skipped for a missing start.
     *
     * Phase 8.9 (P89-PERF-028): the applications whose leg ended in the period are paged by id and
     * each page is measured and folded into running figures — the previous version kept every leg of
     * the period in memory at once (446 MB / 880 s at 1M candidates). Same legs, same arithmetic.
     *
     * @param  Closure(int): int  $targetAt  target days in force at an end instant
     * @return array{durations: list<float>, within: int, applied: array<int, true>, skipped: int}
     */
    private function legStatistics(CandidateStage $from, CandidateStage $to, MetricQuery $query, Closure $targetAt): array
    {
        $statistics = ['durations' => [], 'within' => 0, 'applied' => [], 'skipped' => 0];

        $measure = function (array $ends) use ($from, $targetAt, &$statistics): void {
            if ($ends === []) {
                return;
            }

            $starts = $this->latestStarts($from, $ends);

            foreach ($ends as $applicationId => $end) {
                if (! isset($starts[$applicationId])) {
                    $statistics['skipped']++;

                    continue;
                }

                $days = max(0, $end - $starts[$applicationId]) / 86400;
                $target = $targetAt($end);
                $statistics['durations'][] = $days;
                $statistics['applied'][$target] = true;

                if ($days <= $target) {
                    $statistics['within']++;
                }
            }
        };

        if ($to === CandidateStage::Joined) {
            $zone = MetricPeriod::timezone();

            $this->hires($query)->toBase()
                ->select(['candidate_joinings.id', 'candidate_joinings.candidate_application_id', 'candidate_joinings.actual_doj'])
                ->chunkById(2000, fn (Collection $joinings) => $measure($joinings->mapWithKeys(fn ($joining) => [
                    (int) $joining->candidate_application_id => CarbonImmutable::parse(substr((string) $joining->actual_doj, 0, 10), $zone)->getTimestamp(),
                ])->all()), 'candidate_joinings.id', 'id');

            return $statistics;
        }

        $period = $query->requirePeriod();
        $endEntries = fn ($entries) => $entries
            ->where('candidate_stage_histories.new_stage', $to->value)
            ->tap(fn ($q) => CandidateStageHistory::constrainToMilestoneEntries($q))
            ->tap(fn ($q) => $period->whereTimestampColumn($q, 'candidate_stage_histories.created_at'));

        $this->scope->applications(CandidateApplication::query(), $query)
            ->whereExists(fn ($entries) => $endEntries($entries->selectRaw('1')->from('candidate_stage_histories')
                ->whereColumn('candidate_stage_histories.candidate_application_id', 'candidate_applications.id')))
            ->select('candidate_applications.id')
            ->toBase()
            ->chunkById(2000, function (Collection $applications) use ($endEntries, $measure): void {
                // The leg ends at the application's first entry into the target stage in the period.
                $ends = $endEntries(DB::table('candidate_stage_histories')->whereIn('candidate_stage_histories.candidate_application_id', $applications->pluck('id')))
                    ->groupBy('candidate_stage_histories.candidate_application_id')
                    ->selectRaw('candidate_stage_histories.candidate_application_id, min(candidate_stage_histories.created_at) as ended_at')
                    ->pluck('ended_at', 'candidate_application_id')
                    ->mapWithKeys(fn ($endedAt, $applicationId) => [(int) $applicationId => strtotime((string) $endedAt)])
                    ->all();

                $measure($ends);
            }, 'candidate_applications.id', 'id');

        return $statistics;
    }

    /**
     * For each application, its latest entry into the start stage at or before the leg's end — or,
     * for legs starting at Sourced without such an entry, the application's creation.
     *
     * @param  array<int, int>  $ends  application id => end timestamp
     * @return array<int, int> application id => start timestamp
     */
    private function latestStarts(CandidateStage $from, array $ends): array
    {
        $starts = [];
        $ids = array_keys($ends);

        DB::table('candidate_stage_histories')
            ->whereIn('candidate_application_id', $ids)
            ->where('new_stage', $from->value)
            ->tap(fn ($q) => CandidateStageHistory::constrainToMilestoneEntries($q))
            ->get(['candidate_application_id', 'created_at'])
            ->each(function ($entry) use ($ends, &$starts): void {
                $at = strtotime((string) $entry->created_at);

                if ($at <= $ends[$entry->candidate_application_id] && $at > ($starts[$entry->candidate_application_id] ?? PHP_INT_MIN)) {
                    $starts[$entry->candidate_application_id] = $at;
                }
            });

        if ($from === CandidateStage::Sourced) {
            DB::table('candidate_applications')->whereIn('id', $ids)->get(['id', 'created_at'])
                ->each(function ($application) use (&$starts): void {
                    $starts[$application->id] ??= strtotime((string) $application->created_at);
                });
        }

        return $starts;
    }
}
