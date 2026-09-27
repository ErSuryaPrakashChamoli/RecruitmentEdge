<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\MetricResultStatus;
use App\Models\CandidateApplication;
use App\Models\CandidateStageHistory;
use App\Models\RecruitmentSetting;
use App\Models\User;
use App\Services\Metrics\MetricPeriod;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turn-Around-Time between pipeline checkpoints, computed entirely from candidate_stage_histories
 * (the immutable stage-transition log — see CandidateStageHistory). SLA targets are configurable
 * via RecruitmentSetting rather than hard-coded, following the same default-fallback pattern as
 * RecruitmentAnalyticsService's vacancy_ageing_alert_days/time_to_hire_start_point.
 */
class RecruitmentSlaService
{
    /**
     * Each leg of the pipeline to measure, as [label, from stage, to stage, setting key, default
     * SLA days].
     *
     * @var array<int, array{label: string, from: CandidateStage, to: CandidateStage, setting_key: string, default_days: int}>
     */
    public const array LEGS = [
        ['label' => 'Application -> Screening', 'from' => CandidateStage::Sourced, 'to' => CandidateStage::Screened, 'setting_key' => 'sla_days_application_to_screening', 'default_days' => 2],
        ['label' => 'Shortlist -> Line-up', 'from' => CandidateStage::Shortlisted, 'to' => CandidateStage::InterviewScheduled, 'setting_key' => 'sla_days_shortlist_to_lineup', 'default_days' => 2],
        ['label' => 'Line-up -> Interview', 'from' => CandidateStage::InterviewScheduled, 'to' => CandidateStage::Interview1, 'setting_key' => 'sla_days_lineup_to_interview', 'default_days' => 3],
        ['label' => 'Interview -> Selection', 'from' => CandidateStage::Interview1, 'to' => CandidateStage::Selected, 'setting_key' => 'sla_days_interview_to_selection', 'default_days' => 3],
        ['label' => 'Selection -> Offer', 'from' => CandidateStage::Selected, 'to' => CandidateStage::OfferReleased, 'setting_key' => 'sla_days_selection_to_offer', 'default_days' => 3],
        ['label' => 'Offer -> Acceptance', 'from' => CandidateStage::OfferReleased, 'to' => CandidateStage::OfferAccepted, 'setting_key' => 'sla_days_offer_to_acceptance', 'default_days' => 5],
        ['label' => 'Selection -> Joining', 'from' => CandidateStage::Selected, 'to' => CandidateStage::Joined, 'setting_key' => 'sla_days_selection_to_joining', 'default_days' => 30],
    ];

    public function __construct(
        private readonly HierarchyService $hierarchy,
        private readonly MetricService $metrics,
    ) {}

    /**
     * Per-leg turnaround for legs completed in the period — the governed sla.leg_compliance metric
     * (Phase 8.5): `compliance_percent` is the share of legs completed within target (higher is
     * better); averages and medians are withheld below the minimum sample. Only genuine stage
     * entries count (a rejection, hold or requisition move never restarts or completes a leg).
     *
     * @return Collection<int, array{label: string, average_days: float|null, median_days: float|null, target_days: int, compliance_percent: float|null, breaches: int, sample_size: int}>
     */
    public function stageTat(CarbonInterface $start, CarbonInterface $end, ?User $user = null): Collection
    {
        $result = $this->metrics->get('sla.leg_compliance', MetricQuery::make(MetricPeriod::between($start, $end), $user));

        return collect($result->detail('legs', []))->map(fn (array $leg) => [
            'label' => $leg['label'],
            'average_days' => $leg['average_days'],
            'median_days' => $leg['median_days'],
            'target_days' => $leg['target_days'],
            'compliance_percent' => $leg['compliance_percent'],
            'breaches' => $leg['breaches'],
            'sample_size' => $leg['measured'],
        ]);
    }

    /**
     * Time to hire against its target: the governed median (hiring.time_to_hire) compared with
     * `sla_days_time_to_hire_target` as it was at the end of the period. A 0-day median is a real
     * value, not "no data".
     *
     * @return array{median_days: float|null, target_days: int, status: string, sample_size: int}
     */
    public function timeToHireSummary(CarbonInterface $start, CarbonInterface $end, ?User $user = null): array
    {
        $result = $this->metrics->get('hiring.time_to_hire', MetricQuery::make(MetricPeriod::between($start, $end), $user));
        // Phase 8.6 (D8.6-012): the target in force at the end of the period (or now, for a
        // period still running) — a later target change never re-grades a past period.
        $target = (int) app(RecruitmentSettingService::class)->valueAt('sla_days_time_to_hire_target', $end->lessThan(now()) ? $end : now(), 30);

        return [
            'median_days' => $result->isAvailable() ? $result->value : null,
            'target_days' => $target,
            'status' => match (true) {
                ! $result->isAvailable() => $result->status === MetricResultStatus::InsufficientSample ? 'insufficient_sample' : 'no_data',
                $result->value <= $target => 'on_track',
                default => 'needs_attention',
            },
            'sample_size' => $result->sampleSize,
        ];
    }

    /**
     * Individual, currently-open per-application SLA breaches — active applications still sitting
     * at a leg's `from` stage longer than its target, having not yet reached `to` — for the proactive
     * notification sweep (Section 40/41).
     *
     * Phase 8.5 (PF-1): one query per leg, with the stage-entry time computed in SQL and the breach
     * filtered in SQL; only breaching applications are loaded. A stage with two legs (Selected)
     * reports its first leg only, so one application is one alert. Only genuine stage entries start
     * the clock (DF-9).
     *
     * @return Collection<int, array{application: CandidateApplication, leg_label: string, days_open: int, target_days: int}>
     */
    public function openBreaches(?User $user = null): Collection
    {
        $visibleIds = $user !== null ? $this->hierarchy->visibleEmployeeIdsFor($user) : null;

        return collect(self::LEGS)->unique(fn (array $leg) => $leg['from']->value)->flatMap(function (array $leg) use ($visibleIds) {
            $targetDays = (int) RecruitmentSetting::get($leg['setting_key'], $leg['default_days']);
            $threshold = now()->subDays($targetDays + 1)->format('Y-m-d H:i:s');

            $reached = CandidateApplication::query()
                ->where('current_stage', $leg['from'])
                ->where('status', ApplicationStatus::Active)
                ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds))
                ->select('id')
                ->selectSub($this->legEntrySubquery($leg['from']), 'stage_entered_at')
                ->addSelect('last_activity_at', 'application_date');

            $breaching = DB::query()->fromSub($reached, 'reached')
                ->whereRaw('coalesce(reached.stage_entered_at, reached.last_activity_at, reached.application_date) <= ?', [$threshold])
                ->pluck('reached.stage_entered_at', 'reached.id');

            if ($breaching->isEmpty()) {
                return collect();
            }

            return CandidateApplication::query()->whereIn('id', $breaching->keys())->with('candidate', 'recruiter')->get()
                ->map(function (CandidateApplication $application) use ($breaching, $leg, $targetDays) {
                    $application->stage_entered_at = $breaching->get($application->id);

                    return $this->legBreach($application, $leg, $targetDays);
                })
                ->filter();
        })->values();
    }

    /**
     * Phase 4 integration point: open breaches of a configured pipeline stage's own `sla_hours`
     * (set in the Stage Builder / template, snapshotted per requisition). Complements the
     * setting-driven legs above rather than replacing them — both feed the same hourly alert sweep
     * (notifications:dispatch-alerts), so this remains the single SLA engine. Entry time is the
     * latest genuine move into the application's current configured stage (a hold or reactivation
     * does not restart the clock), falling back to last_activity_at for applications backfilled onto
     * a pipeline.
     *
     * @return Collection<int, array{application: CandidateApplication, stage_name: string, hours_open: int, target_hours: int}>
     */
    public function openPipelineStageBreaches(?User $user = null): Collection
    {
        $visibleIds = $user !== null ? $this->hierarchy->visibleEmployeeIdsFor($user) : null;

        return CandidateApplication::query()
            ->where('status', ApplicationStatus::Active)
            ->whereHas('pipelineStage', fn (Builder $q) => $q->whereNotNull('sla_hours'))
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds))
            ->addSelect(['stage_entered_at' => $this->pipelineEntrySubquery()])
            ->with('candidate', 'recruiter', 'pipelineStage')
            ->get()
            ->map(fn (CandidateApplication $application): ?array => $this->pipelineStageBreach($application))
            ->filter()
            ->values();
    }

    /**
     * Phase 6 automation condition: the SLA this one application is currently breaching (its
     * configured pipeline stage's sla_hours first, then the setting-driven legs), or null. Same
     * rules as the sweeps above, evaluated for a single record.
     */
    public function breachFor(CandidateApplication $application): ?string
    {
        return $this->breachesForApplications(collect([$application]))->first()[1] ?? null;
    }

    /**
     * breachFor() for many applications at once, with the stage-entry times preloaded in two
     * queries (Hiring Health facts, DF-12).
     *
     * @param  Collection<int, CandidateApplication>  $applications
     * @return Collection<int, array{0: CandidateApplication, 1: string}>
     */
    public function breachesForApplications(Collection $applications): Collection
    {
        $active = $applications->filter(fn (CandidateApplication $a) => $a->status === ApplicationStatus::Active)->values();

        if ($active->isEmpty()) {
            return collect();
        }

        $ids = $active->pluck('id');
        $pipelineEntries = CandidateStageHistory::query()->pipelineStageEntries()->whereIn('candidate_application_id', $ids)
            ->orderBy('created_at')->get(['candidate_application_id', 'new_pipeline_stage_id', 'created_at']);
        $milestoneEntries = CandidateStageHistory::query()->milestoneEntries()->whereIn('candidate_application_id', $ids)
            ->orderBy('created_at')->get(['candidate_application_id', 'new_stage', 'created_at']);

        return $active->map(function (CandidateApplication $application) use ($pipelineEntries, $milestoneEntries): ?array {
            if ($application->pipeline_stage_id !== null && $application->pipelineStage?->sla_hours !== null) {
                $application->stage_entered_at ??= $pipelineEntries
                    ->where('candidate_application_id', $application->id)
                    ->where('new_pipeline_stage_id', $application->pipeline_stage_id)
                    ->last()?->created_at;

                $breach = $this->pipelineStageBreach($application);

                if ($breach !== null) {
                    return [$application, "{$breach['stage_name']} for {$breach['hours_open']} hours (SLA {$breach['target_hours']} hours)"];
                }
            }

            foreach (collect(self::LEGS)->unique(fn (array $leg) => $leg['from']->value) as $leg) {
                if ($application->current_stage !== $leg['from']) {
                    continue;
                }

                $copy = clone $application;
                $copy->stage_entered_at = $milestoneEntries
                    ->where('candidate_application_id', $application->id)
                    ->where('new_stage', $leg['from'])
                    ->last()?->created_at;
                $breach = $this->legBreach($copy, $leg, (int) RecruitmentSetting::get($leg['setting_key'], $leg['default_days']));

                if ($breach !== null) {
                    return [$application, "{$breach['leg_label']} for {$breach['days_open']} days (target {$breach['target_days']})"];
                }
            }

            return null;
        })->filter()->values();
    }

    /**
     * The latest genuine entry into $stage, for an application row (subquery).
     */
    private function legEntrySubquery(CandidateStage $stage): Builder
    {
        return CandidateStageHistory::query()->milestoneEntries()
            ->select('created_at')
            ->whereColumn('candidate_application_id', 'candidate_applications.id')
            ->where('new_stage', $stage)
            ->latest('created_at')
            ->limit(1);
    }

    /**
     * The latest genuine move into the application's current configured stage (subquery).
     */
    private function pipelineEntrySubquery(): Builder
    {
        return CandidateStageHistory::query()->pipelineStageEntries()
            ->select('created_at')
            ->whereColumn('candidate_application_id', 'candidate_applications.id')
            ->whereColumn('new_pipeline_stage_id', 'candidate_applications.pipeline_stage_id')
            ->latest('created_at')
            ->limit(1);
    }

    /**
     * @param  array{label: string, from: CandidateStage, to: CandidateStage, setting_key: string, default_days: int}  $leg
     * @return array{application: CandidateApplication, leg_label: string, days_open: int, target_days: int}|null
     */
    private function legBreach(CandidateApplication $application, array $leg, int $targetDays): ?array
    {
        $reachedAt = ($application->stage_entered_at !== null ? Carbon::parse($application->stage_entered_at) : null)
            ?? $application->last_activity_at
            ?? $application->application_date;

        $daysOpen = $reachedAt !== null ? (int) $reachedAt->diffInDays(now()) : 0;

        return $daysOpen > $targetDays ? [
            'application' => $application,
            'leg_label' => $leg['label'],
            'days_open' => $daysOpen,
            'target_days' => $targetDays,
        ] : null;
    }

    /**
     * @return array{application: CandidateApplication, stage_name: string, hours_open: int, target_hours: int}|null
     */
    private function pipelineStageBreach(CandidateApplication $application): ?array
    {
        $enteredAt = $application->stage_entered_at !== null
            ? Carbon::parse($application->stage_entered_at)
            : ($application->last_activity_at ?? $application->created_at);
        $hoursOpen = (int) $enteredAt->diffInHours(now());
        $target = (int) $application->pipelineStage->sla_hours;

        return $hoursOpen > $target ? [
            'application' => $application,
            'stage_name' => $application->pipelineStage->name,
            'hours_open' => $hoursOpen,
            'target_hours' => $target,
        ] : null;
    }
}
