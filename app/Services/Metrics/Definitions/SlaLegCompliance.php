<?php

namespace App\Services\Metrics\Definitions;

use App\Enums\CandidateStage;
use App\Enums\MetricCategory;
use App\Enums\MetricKind;
use App\Enums\MetricMaterialization;
use App\Enums\MetricScopeModel;
use App\Enums\MetricUnit;
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
            $targetAt = fn (int $end): int => (int) $settings->valueAt($leg['setting_key'], CarbonImmutable::createFromTimestamp($end), $leg['default_days']);
            [$legs, $skipped] = $this->legDurations($leg['from'], $leg['to'], $query);
            $durations = $legs->pluck('days');
            $applied = $legs->map(fn (array $completed) => $targetAt($completed['end']));
            $within = $legs->filter(fn (array $completed, int $index) => $completed['days'] <= $applied[$index])->count();

            return [
                'label' => $leg['label'],
                'target_days' => $targetAt($periodEnd->getTimestamp()),
                'targets_applied' => $applied->unique()->sort()->values()->all(),
                'measured' => $durations->count(),
                'within_target' => $within,
                'breaches' => $durations->count() - $within,
                'skipped_start' => $skipped,
                'compliance_percent' => $this->withheld($this->rate($within, $durations->count()), $durations->count()),
                'average_days' => $durations->isNotEmpty() && $durations->count() >= $this->spec()->minimumSample() ? MetricUnit::Days->round((float) $durations->avg()) : null,
                'median_days' => $durations->isNotEmpty() && $durations->count() >= $this->spec()->minimumSample() ? MetricUnit::Days->round((float) $durations->median()) : null,
            ];
        });

        $measured = (int) $legs->sum('measured');

        return $this->result($query, $this->rate((int) $legs->sum('within_target'), $measured), $measured, ['legs' => $legs->all()], excluded: (int) $legs->sum('skipped_start'));
    }

    /**
     * The legs completed in the period — duration in days and end instant (timestamp) — from plain
     * rows and integer timestamps, plus how many were skipped for a missing start.
     *
     * @return array{0: Collection<int, array{days: float, end: int}>, 1: int}
     */
    private function legDurations(CandidateStage $from, CandidateStage $to, MetricQuery $query): array
    {
        $period = $query->requirePeriod();
        $zone = MetricPeriod::timezone();

        $ends = $to === CandidateStage::Joined
            ? $this->hires($query)->toBase()->get(['candidate_joinings.candidate_application_id', 'candidate_joinings.actual_doj'])
                ->mapWithKeys(fn ($joining) => [$joining->candidate_application_id => CarbonImmutable::parse(substr((string) $joining->actual_doj, 0, 10), $zone)->getTimestamp()])
            : $this->scope->throughApplication(CandidateStageHistory::query()->milestoneEntries(), $query)
                ->where('candidate_stage_histories.new_stage', $to->value)
                ->tap(fn ($q) => $period->whereTimestampColumn($q, 'candidate_stage_histories.created_at'))
                ->orderBy('candidate_stage_histories.created_at')
                ->toBase()
                ->get(['candidate_stage_histories.candidate_application_id', 'candidate_stage_histories.created_at'])
                ->unique('candidate_application_id')
                ->mapWithKeys(fn ($entry) => [$entry->candidate_application_id => strtotime((string) $entry->created_at)]);

        if ($ends->isEmpty()) {
            return [collect(), 0];
        }

        $starts = [];

        foreach ($ends->keys()->chunk(2000) as $chunk) {
            DB::table('candidate_stage_histories')
                ->whereIn('candidate_application_id', $chunk)
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
                DB::table('candidate_applications')->whereIn('id', $chunk)->get(['id', 'created_at'])
                    ->each(function ($application) use (&$starts): void {
                        $starts[$application->id] ??= strtotime((string) $application->created_at);
                    });
            }
        }

        $legs = $ends->map(fn (int $end, int $id) => isset($starts[$id]) ? ['days' => max(0, $end - $starts[$id]) / 86400, 'end' => $end] : null);

        return [$legs->filter()->values(), $legs->filter(fn (?array $leg) => $leg === null)->count()];
    }
}
