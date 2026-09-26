<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Models\CandidateApplication;
use App\Models\CandidateStageHistory;
use App\Models\RecruitmentSetting;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
    private const array LEGS = [
        ['label' => 'Application -> Screening', 'from' => CandidateStage::Sourced, 'to' => CandidateStage::Screened, 'setting_key' => 'sla_days_application_to_screening', 'default_days' => 2],
        ['label' => 'Shortlist -> Line-up', 'from' => CandidateStage::Shortlisted, 'to' => CandidateStage::InterviewScheduled, 'setting_key' => 'sla_days_shortlist_to_lineup', 'default_days' => 2],
        ['label' => 'Line-up -> Interview', 'from' => CandidateStage::InterviewScheduled, 'to' => CandidateStage::Interview1, 'setting_key' => 'sla_days_lineup_to_interview', 'default_days' => 3],
        ['label' => 'Interview -> Selection', 'from' => CandidateStage::Interview1, 'to' => CandidateStage::Selected, 'setting_key' => 'sla_days_interview_to_selection', 'default_days' => 3],
        ['label' => 'Selection -> Offer', 'from' => CandidateStage::Selected, 'to' => CandidateStage::OfferReleased, 'setting_key' => 'sla_days_selection_to_offer', 'default_days' => 3],
        ['label' => 'Offer -> Acceptance', 'from' => CandidateStage::OfferReleased, 'to' => CandidateStage::OfferAccepted, 'setting_key' => 'sla_days_offer_to_acceptance', 'default_days' => 5],
        ['label' => 'Selection -> Joining', 'from' => CandidateStage::Selected, 'to' => CandidateStage::Joined, 'setting_key' => 'sla_days_selection_to_joining', 'default_days' => 30],
    ];

    public function __construct(private readonly HierarchyService $hierarchy) {}

    /**
     * `sla_percent` follows the same convention as timeToHireSummary(): actual / target x 100, so
     * over 100% means the leg is running slower than its SLA target (e.g. 128% = needs attention),
     * not an achievement score to maximize.
     *
     * @return Collection<int, array{label: string, average_days: float|null, median_days: float|null, target_days: int, sla_percent: float|null, breaches: int, sample_size: int}>
     */
    public function stageTat(CarbonInterface $start, CarbonInterface $end, ?User $user = null): Collection
    {
        $visibleIds = $user !== null ? $this->hierarchy->visibleEmployeeIdsFor($user) : null;

        return collect(self::LEGS)->map(function (array $leg) use ($start, $end, $visibleIds) {
            $targetDays = (int) RecruitmentSetting::get($leg['setting_key'], $leg['default_days']);

            $reachedFrom = CandidateStageHistory::query()
                ->where('new_stage', $leg['from'])
                ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)))
                ->pluck('created_at', 'candidate_application_id');

            $reachedTo = CandidateStageHistory::query()
                ->where('new_stage', $leg['to'])
                ->whereBetween('created_at', [$start, $end])
                ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)))
                ->pluck('created_at', 'candidate_application_id');

            $durations = $reachedTo
                ->map(function ($toDate, $applicationId) use ($reachedFrom) {
                    $fromDate = $reachedFrom->get($applicationId);

                    return $fromDate !== null ? $fromDate->diffInHours($toDate) / 24 : null;
                })
                ->filter(fn (?float $days) => $days !== null && $days >= 0)
                ->values();

            $average = $durations->isNotEmpty() ? round($durations->avg(), 1) : null;
            $median = $durations->isNotEmpty() ? round($durations->median(), 1) : null;
            $breaches = $durations->filter(fn (float $days) => $days > $targetDays)->count();

            return [
                'label' => $leg['label'],
                'average_days' => $average,
                'median_days' => $median,
                'target_days' => $targetDays,
                'sla_percent' => $average !== null ? round($average / $targetDays * 100, 1) : null,
                'breaches' => $breaches,
                'sample_size' => $durations->count(),
            ];
        });
    }

    /**
     * A single "time to hire vs target" summary card, reusing RecruitmentAnalyticsService's average
     * time-to-hire rather than recomputing it.
     *
     * @return array{average_days: float|null, target_days: int, sla_percent: float|null, status: string}
     */
    public function timeToHireSummary(CarbonInterface $start, CarbonInterface $end, ?User $user = null): array
    {
        $average = app(RecruitmentAnalyticsService::class)->averageTimeToHireDays($start, $end, $user);
        $target = (int) RecruitmentSetting::get('sla_days_time_to_hire_target', 30);

        $slaPercent = $average !== null && $average > 0 ? round($average / $target * 100, 1) : null;

        return [
            'average_days' => $average,
            'target_days' => $target,
            'sla_percent' => $slaPercent,
            'status' => $slaPercent === null ? 'no_data' : ($slaPercent <= 100 ? 'on_track' : 'needs_attention'),
        ];
    }

    /**
     * Individual, currently-open per-application SLA breaches — applications still sitting at a
     * leg's `from` stage longer than its target, having not yet reached `to`. Unlike stageTat()'s
     * closed-interval aggregates, this identifies specific breaching records, for the proactive
     * notification sweep (Section 40/41).
     *
     * @return Collection<int, array{application: CandidateApplication, leg_label: string, days_open: int, target_days: int}>
     */
    public function openBreaches(?User $user = null): Collection
    {
        $visibleIds = $user !== null ? $this->hierarchy->visibleEmployeeIdsFor($user) : null;

        return collect(self::LEGS)->flatMap(function (array $leg) use ($visibleIds) {
            $targetDays = (int) RecruitmentSetting::get($leg['setting_key'], $leg['default_days']);

            return CandidateApplication::query()
                ->where('current_stage', $leg['from'])
                ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds))
                ->with('candidate', 'recruiter')
                ->get()
                ->map(fn (CandidateApplication $application) => $this->legBreach($application, $leg, $targetDays))
                ->filter();
        })->values();
    }

    /**
     * Phase 4 integration point: open breaches of a configured pipeline stage's own `sla_hours`
     * (set in the Stage Builder / template, snapshotted per requisition). Complements the
     * setting-driven legs above rather than replacing them — both feed the same hourly alert sweep
     * (notifications:dispatch-alerts), so this remains the single SLA engine. Entry time is the
     * latest history row that moved the application into its current configured stage, falling
     * back to last_activity_at for applications backfilled onto a pipeline.
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
            ->addSelect(['stage_entered_at' => CandidateStageHistory::query()
                ->select('created_at')
                ->whereColumn('candidate_application_id', 'candidate_applications.id')
                ->whereColumn('new_pipeline_stage_id', 'candidate_applications.pipeline_stage_id')
                ->latest('created_at')
                ->limit(1),
            ])
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
        if ($application->status !== ApplicationStatus::Active) {
            return null;
        }

        if ($application->pipelineStage?->sla_hours !== null) {
            $application->stage_entered_at ??= CandidateStageHistory::query()
                ->where('candidate_application_id', $application->id)
                ->where('new_pipeline_stage_id', $application->pipeline_stage_id)
                ->latest('created_at')
                ->value('created_at');

            $breach = $this->pipelineStageBreach($application);

            if ($breach !== null) {
                return "{$breach['stage_name']} for {$breach['hours_open']} hours (SLA {$breach['target_hours']} hours)";
            }
        }

        foreach (self::LEGS as $leg) {
            if ($application->current_stage !== $leg['from']) {
                continue;
            }

            $breach = $this->legBreach($application, $leg, (int) RecruitmentSetting::get($leg['setting_key'], $leg['default_days']));

            if ($breach !== null) {
                return "{$breach['leg_label']} for {$breach['days_open']} days (target {$breach['target_days']})";
            }
        }

        return null;
    }

    /**
     * @param  array{label: string, from: CandidateStage, to: CandidateStage, setting_key: string, default_days: int}  $leg
     * @return array{application: CandidateApplication, leg_label: string, days_open: int, target_days: int}|null
     */
    private function legBreach(CandidateApplication $application, array $leg, int $targetDays): ?array
    {
        $reachedAt = $application->stageHistory()->where('new_stage', $leg['from'])->value('created_at')
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
