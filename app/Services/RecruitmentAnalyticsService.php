<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\InterviewResult;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Enums\RequisitionStatus;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\CandidateSource;
use App\Models\CandidateStageHistory;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\OfferStatusHistory;
use App\Models\RecruitmentCost;
use App\Models\RecruitmentRequisition;
use App\Models\RecruitmentSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Section 32 (funnel), 33 (source analytics), 9 (vacancy ageing), and 35 (time to hire).
 * Everything here is read-only reporting over facts already recorded elsewhere — no new state.
 */
class RecruitmentAnalyticsService
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    /**
     * Distinct applications that reached each canonical stage within the range, with conversion %
     * relative to Sourced. "Sourced" is counted from `candidate_applications.application_date`
     * rather than stage history, since an application's initial stage is set directly on creation
     * and never passes through StageTransitionService (there's nothing to transition *from*).
     *
     * @return Collection<int, array{stage: CandidateStage, count: int, conversion_from_sourced: float|null}>
     */
    public function funnel(CarbonInterface $start, CarbonInterface $end, ?User $user = null): Collection
    {
        $visibleIds = $user !== null ? $this->hierarchy->visibleEmployeeIdsFor($user) : null;

        $sourcedCount = CandidateApplication::query()
            ->whereBetween('application_date', [$start->toDateString(), $end->toDateString()])
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds))
            ->count();

        return collect(CandidateStage::cases())->map(function (CandidateStage $stage) use ($start, $end, $visibleIds, $sourcedCount) {
            $count = $stage === CandidateStage::Sourced
                ? $sourcedCount
                : CandidateStageHistory::query()
                    ->where('new_stage', $stage)
                    ->whereBetween('created_at', [$start, $end])
                    ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas(
                        'candidateApplication',
                        fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds),
                    ))
                    ->distinct('candidate_application_id')
                    ->count('candidate_application_id');

            return [
                'stage' => $stage,
                'count' => $count,
                'conversion_from_sourced' => $sourcedCount > 0 ? round($count / $sourcedCount * 100, 1) : null,
            ];
        });
    }

    /**
     * Per-source funnel (Sourced -> Connected -> Interested -> Interviewed -> Selected -> Offers ->
     * Joined) plus spend and cost-per-outcome, for identifying true source ROI (Section 33/34) —
     * "which source actually produces joining employees, at what cost."
     *
     * @return Collection<int, array{source: CandidateSource, spend: float, sourced: int, connected: int, interested: int, interviewed: int, selected: int, offers: int, joined: int, conversion_percent: float|null, cost_per_interview: float|null, cost_per_selection: float|null, cost_per_join: float|null}>
     */
    public function sourceAnalytics(CarbonInterface $start, CarbonInterface $end, ?User $user = null): Collection
    {
        $visibleIds = $user !== null ? $this->hierarchy->visibleEmployeeIdsFor($user) : null;

        return CandidateSource::query()->get()->map(function (CandidateSource $source) use ($start, $end, $visibleIds, $user) {
            $candidateIds = Candidate::query()
                ->where('source_id', $source->id)
                ->whereBetween('created_at', [$start, $end])
                ->when($visibleIds !== null, fn (Builder $q) => $q->where(function (Builder $q2) use ($visibleIds, $user): void {
                    $q2->whereHas('applications', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds));

                    if ($user->employee_id !== null) {
                        $q2->orWhere('created_by', $user->employee_id);
                    }
                }))
                ->pluck('id');

            $applicationIds = CandidateApplication::query()->whereIn('candidate_id', $candidateIds)->pluck('id');

            $interviewed = Interview::query()
                ->whereIn('candidate_application_id', $applicationIds)
                ->where('status', InterviewStatus::Completed)
                ->distinct('candidate_application_id')
                ->count('candidate_application_id');

            $selected = CandidateStageHistory::query()
                ->whereIn('candidate_application_id', $applicationIds)
                ->where('new_stage', CandidateStage::Selected)
                ->distinct('candidate_application_id')
                ->count('candidate_application_id');

            $joined = CandidateJoining::query()
                ->whereIn('candidate_application_id', $applicationIds)
                ->where('status', JoiningStatus::Joined)
                ->count();

            $spend = (float) RecruitmentCost::query()
                ->where('source_id', $source->id)
                ->whereBetween('incurred_on', [$start->toDateString(), $end->toDateString()])
                ->sum('amount');

            return [
                'source' => $source,
                'spend' => $spend,
                'sourced' => $candidateIds->count(),
                'connected' => CandidateStageHistory::query()
                    ->whereIn('candidate_application_id', $applicationIds)
                    ->where('new_stage', CandidateStage::Connected)
                    ->distinct('candidate_application_id')
                    ->count('candidate_application_id'),
                'interested' => CandidateStageHistory::query()
                    ->whereIn('candidate_application_id', $applicationIds)
                    ->where('new_stage', CandidateStage::Interested)
                    ->distinct('candidate_application_id')
                    ->count('candidate_application_id'),
                'interviewed' => $interviewed,
                'selected' => $selected,
                'offers' => Offer::query()
                    ->whereIn('candidate_application_id', $applicationIds)
                    ->distinct('candidate_application_id')
                    ->count('candidate_application_id'),
                'joined' => $joined,
                'conversion_percent' => $candidateIds->count() > 0 ? round($joined / $candidateIds->count() * 100, 1) : null,
                'cost_per_interview' => $interviewed > 0 ? round($spend / $interviewed, 2) : null,
                'cost_per_selection' => $selected > 0 ? round($spend / $selected, 2) : null,
                'cost_per_join' => $joined > 0 ? round($spend / $joined, 2) : null,
            ];
        });
    }

    /**
     * Open/on-hold requisitions that have crossed the configurable `vacancy_ageing_alert_days`
     * threshold (Section 36), oldest first, with their priority label. Pass
     * `$includeWithinThreshold: true` to get every open/on-hold requisition instead (positionHealth()
     * needs the full set, since pipeline-based risk applies to young requisitions too).
     *
     * @return Collection<int, array{requisition: RecruitmentRequisition, ageing_days: int, is_overdue: bool, priority: string|null}>
     */
    public function vacancyAgeing(?User $user = null, bool $includeWithinThreshold = false): Collection
    {
        $visibleIds = $user !== null ? $this->hierarchy->visibleEmployeeIdsFor($user) : null;
        $thresholdDays = (int) RecruitmentSetting::get('vacancy_ageing_alert_days', 30);

        return RecruitmentRequisition::query()
            ->whereIn('status', [RequisitionStatus::Open, RequisitionStatus::OnHold])
            ->when($visibleIds !== null, fn (Builder $q) => $q->where(function (Builder $q2) use ($visibleIds): void {
                $q2->whereIn('manager_id', $visibleIds)
                    ->orWhereIn('assistant_manager_id', $visibleIds)
                    ->orWhereIn('vp_hr_id', $visibleIds)
                    ->orWhereIn('created_by', $visibleIds)
                    ->orWhereHas('recruiters', fn (Builder $r) => $r->whereIn('employees.id', $visibleIds));
            }))
            ->get()
            ->map(fn (RecruitmentRequisition $requisition) => [
                'requisition' => $requisition,
                'ageing_days' => $requisition->ageingInDays(),
                'is_overdue' => $requisition->ageingInDays() > $thresholdDays,
                'priority' => $requisition->priority?->label(),
            ])
            ->when(! $includeWithinThreshold, fn (Collection $rows) => $rows->where('is_overdue', true))
            ->sortByDesc('ageing_days')
            ->values();
    }

    /**
     * Average days from the configurable start point (`time_to_hire_start_point` — one of
     * requisition_opened/candidate_sourced/candidate_applied, default candidate_applied) to actual
     * joining, for joins within the range (Section 35).
     */
    public function averageTimeToHireDays(CarbonInterface $start, CarbonInterface $end, ?User $user = null): ?float
    {
        $startPoint = RecruitmentSetting::get('time_to_hire_start_point', 'candidate_applied');
        $visibleIds = $user !== null ? $this->hierarchy->visibleEmployeeIdsFor($user) : null;

        $joinings = CandidateJoining::query()
            ->where('status', JoiningStatus::Joined)
            ->whereBetween('actual_doj', [$start->toDateString(), $end->toDateString()])
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas(
                'candidateApplication',
                fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds),
            ))
            ->with(['candidateApplication.requisition', 'candidateApplication.candidate'])
            ->get();

        if ($joinings->isEmpty()) {
            return null;
        }

        $days = $joinings->map(function (CandidateJoining $joining) use ($startPoint) {
            $application = $joining->candidateApplication;

            $startDate = match ($startPoint) {
                'requisition_opened' => $application->requisition->opening_date ?? $application->requisition->created_at,
                'candidate_sourced' => $application->candidate->created_at,
                default => $application->application_date,
            };

            return $startDate->diffInDays($joining->actual_doj);
        });

        return round($days->avg(), 1);
    }

    /**
     * Turn-up ratio for a period: of the interviews scheduled ("line-ups"), how many were actually
     * held ("turn-ups", status Completed) vs no-show/cancelled/rescheduled. Turn-up % = Turn-ups /
     * Line-ups x 100, null when there were no line-ups in the period.
     *
     * @return array{lineups: int, turnups: int, no_shows: int, cancelled: int, rescheduled: int, turnup_percent: float|null}
     */
    public function turnUpAnalysis(CarbonInterface $start, CarbonInterface $end, ?User $user = null): array
    {
        $interviews = $this->scopedInterviews($start, $end, $user)->get(['id', 'status', 'scheduled_at']);

        $lineups = $interviews->count();
        $turnups = $interviews->where('status', InterviewStatus::Completed)->count();

        return [
            'lineups' => $lineups,
            'turnups' => $turnups,
            'no_shows' => $interviews->where('status', InterviewStatus::NoShow)->count(),
            'cancelled' => $interviews->where('status', InterviewStatus::Cancelled)->count(),
            'rescheduled' => $interviews->where('status', InterviewStatus::Rescheduled)->count(),
            'turnup_percent' => $lineups > 0 ? round($turnups / $lineups * 100, 1) : null,
        ];
    }

    /**
     * Day-by-day line-up/turn-up/no-show counts for a trend chart (Sections 4/17's trend and
     * no-show requirements are the same underlying series, so they share this one method).
     *
     * @return Collection<int, array{date: string, lineups: int, turnups: int, no_shows: int, turnup_percent: float|null}>
     */
    public function turnUpTrend(CarbonInterface $start, CarbonInterface $end, ?User $user = null): Collection
    {
        $interviews = $this->scopedInterviews($start, $end, $user)->get(['id', 'status', 'scheduled_at']);

        $period = collect();
        for ($day = $start->copy()->startOfDay(); $day->lte($end); $day = $day->addDay()) {
            $period->push($day->toDateString());
        }

        $byDate = $interviews->groupBy(fn (Interview $i) => $i->scheduled_at->toDateString());

        return $period->map(function (string $date) use ($byDate) {
            $dayInterviews = $byDate->get($date, collect());
            $lineups = $dayInterviews->count();
            $turnups = $dayInterviews->where('status', InterviewStatus::Completed)->count();

            return [
                'date' => $date,
                'lineups' => $lineups,
                'turnups' => $turnups,
                'no_shows' => $dayInterviews->where('status', InterviewStatus::NoShow)->count(),
                'turnup_percent' => $lineups > 0 ? round($turnups / $lineups * 100, 1) : null,
            ];
        });
    }

    /**
     * Expected vs actual joins per period bucket — distinct from turnUpTrend(), which is about
     * interview line-ups. "Expected" counts joinings whose expected_doj falls in the bucket (any
     * status); "joined" counts Joined records whose actual_doj falls in the bucket. Buckets are days
     * for ranges up to 31 days, ISO weeks up to ~6 months, and calendar months beyond that.
     *
     * @return Collection<int, array{period: string, start: string, end: string, expected: int, joined: int}>
     */
    public function joiningTrend(CarbonInterface $start, CarbonInterface $end, ?User $user = null): Collection
    {
        $visibleIds = $user !== null ? $this->hierarchy->visibleEmployeeIdsFor($user) : null;
        $rangeStart = CarbonImmutable::instance($start)->startOfDay();
        $rangeEnd = CarbonImmutable::instance($end)->startOfDay();
        $days = (int) $rangeStart->diffInDays($rangeEnd) + 1;

        $scope = fn (Builder $q) => $q->when($visibleIds !== null, fn (Builder $scoped) => $scoped->whereHas(
            'candidateApplication',
            fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds),
        ));

        // whereDate bounds (not whereBetween) because date casts persist "Y-m-d H:i:s", which a plain
        // string BETWEEN on SQLite would exclude on the range's last day.
        $expectedDates = CandidateJoining::query()
            ->whereDate('expected_doj', '>=', $rangeStart->toDateString())
            ->whereDate('expected_doj', '<=', $rangeEnd->toDateString())
            ->tap($scope)
            ->pluck('expected_doj')
            ->map(fn ($date) => Carbon::parse($date)->toDateString());

        $joinedDates = CandidateJoining::query()
            ->where('status', JoiningStatus::Joined)
            ->whereDate('actual_doj', '>=', $rangeStart->toDateString())
            ->whereDate('actual_doj', '<=', $rangeEnd->toDateString())
            ->tap($scope)
            ->pluck('actual_doj')
            ->map(fn ($date) => Carbon::parse($date)->toDateString());

        [$alignStart, $step, $format] = match (true) {
            $days <= 31 => [fn (CarbonImmutable $d) => $d, fn (CarbonImmutable $d) => $d->addDay(), 'd M'],
            $days <= 183 => [fn (CarbonImmutable $d) => $d->startOfWeek(), fn (CarbonImmutable $d) => $d->addWeek(), 'd M'],
            default => [fn (CarbonImmutable $d) => $d->startOfMonth(), fn (CarbonImmutable $d) => $d->addMonthNoOverflow(), 'M Y'],
        };

        $buckets = collect();

        for ($cursor = $alignStart($rangeStart); $cursor->lte($rangeEnd); $cursor = $step($cursor)) {
            $bucketStart = $cursor->max($rangeStart)->toDateString();
            $bucketEnd = $step($cursor)->subDay()->min($rangeEnd)->toDateString();
            $inBucket = fn (string $date): bool => $date >= $bucketStart && $date <= $bucketEnd;

            $buckets->push([
                'period' => $cursor->max($rangeStart)->format($format),
                'start' => $bucketStart,
                'end' => $bucketEnd,
                'expected' => $expectedDates->filter($inBucket)->count(),
                'joined' => $joinedDates->filter($inBucket)->count(),
            ]);
        }

        return $buckets;
    }

    /**
     * Turn-up -> Selection -> Joining ratios grouped by recruiter, requisition, or source (Sections
     * 5/6/14 — the same three-step conversion, sliced along whichever dimension the caller needs).
     *
     * @param  'recruiter'|'requisition'|'source'  $groupBy
     * @return Collection<int, array{group: string, turnups: int, selections: int, joined: int, selection_ratio: float|null, joining_ratio: float|null}>
     */
    public function conversionBreakdown(string $groupBy, CarbonInterface $start, CarbonInterface $end, ?User $user = null): Collection
    {
        $visibleIds = $user !== null ? $this->hierarchy->visibleEmployeeIdsFor($user) : null;

        $applications = CandidateApplication::query()
            ->whereBetween('application_date', [$start->toDateString(), $end->toDateString()])
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds))
            ->with(['recruiter:id,first_name,last_name', 'requisition:id,code', 'candidate:id,source_id', 'candidate.source:id,name'])
            ->get();

        return $applications
            ->groupBy(fn (CandidateApplication $application) => match ($groupBy) {
                'requisition' => $application->requisition?->code ?? 'Unassigned',
                'source' => $application->candidate?->source?->name ?? 'Unknown',
                default => $application->recruiter?->fullName() ?? 'Unassigned',
            })
            ->map(function (Collection $group, string $label) {
                $applicationIds = $group->pluck('id');

                $turnups = Interview::query()
                    ->whereIn('candidate_application_id', $applicationIds)
                    ->where('status', InterviewStatus::Completed)
                    ->distinct('candidate_application_id')
                    ->count('candidate_application_id');

                $selections = CandidateStageHistory::query()
                    ->whereIn('candidate_application_id', $applicationIds)
                    ->where('new_stage', CandidateStage::Selected)
                    ->distinct('candidate_application_id')
                    ->count('candidate_application_id');

                $joined = CandidateJoining::query()
                    ->whereIn('candidate_application_id', $applicationIds)
                    ->where('status', JoiningStatus::Joined)
                    ->count();

                return [
                    'group' => $label,
                    'turnups' => $turnups,
                    'selections' => $selections,
                    'joined' => $joined,
                    'selection_ratio' => $turnups > 0 ? round($selections / $turnups * 100, 1) : null,
                    'joining_ratio' => $selections > 0 ? round($joined / $selections * 100, 1) : null,
                ];
            })
            ->values();
    }

    /**
     * How long currently-active applications have sat in each of a curated set of pipeline
     * checkpoints, bucketed by days since their last recorded activity (Section 12).
     *
     * @return Collection<int, array{stage: CandidateStage, total: int, buckets: array{0_2: int, 3_5: int, 6_10: int, 10_plus: int}}>
     */
    public function candidateAging(?User $user = null): Collection
    {
        $visibleIds = $user !== null ? $this->hierarchy->visibleEmployeeIdsFor($user) : null;
        $stages = [
            CandidateStage::Sourced,
            CandidateStage::Shortlisted,
            CandidateStage::InterviewScheduled,
            CandidateStage::Selected,
            CandidateStage::OfferReleased,
        ];

        return collect($stages)->map(function (CandidateStage $stage) use ($visibleIds) {
            $applications = CandidateApplication::query()
                ->where('current_stage', $stage)
                ->where('status', ApplicationStatus::Active)
                ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds))
                ->get(['id', 'last_activity_at']);

            $buckets = ['0_2' => 0, '3_5' => 0, '6_10' => 0, '10_plus' => 0];

            foreach ($applications as $application) {
                $days = $application->last_activity_at?->diffInDays(now()) ?? 0;

                $buckets[match (true) {
                    $days <= 2 => '0_2',
                    $days <= 5 => '3_5',
                    $days <= 10 => '6_10',
                    default => '10_plus',
                }]++;
            }

            return ['stage' => $stage, 'total' => $applications->count(), 'buckets' => $buckets];
        });
    }

    /**
     * Fulfilment, pipeline size, and a configurable risk flag for every open/on-hold requisition
     * (Sections 15/20). Built on top of vacancyAgeing() rather than re-deriving the ageing/overdue
     * calculation. Risk thresholds are configurable via RecruitmentSetting, not hard-coded.
     *
     * Deliberately does NOT treat a low fulfilment_percent on its own as a risk signal: a
     * freshly-opened requisition legitimately has 0% fulfilment on day one, so that would flag
     * every new position as "at risk" regardless of how healthy its pipeline actually is.
     * fulfilment_percent is still returned for display; risk is driven only by the leading
     * indicators (ageing overdue, pipeline thin relative to what's left to fill, or no pipeline at
     * all).
     *
     * @return Collection<int, array{requisition: RecruitmentRequisition, required: int, filled: int, remaining: int, fulfilment_percent: float, pipeline: int, ageing_days: int, is_overdue: bool, risk: string}>
     */
    public function positionHealth(?User $user = null): Collection
    {
        $minPipelineRatio = (float) RecruitmentSetting::get('position_risk_min_pipeline_ratio', 2.0);
        $maxDaysOpen = (int) RecruitmentSetting::get('position_risk_max_days_open', 45);

        return $this->vacancyAgeing($user, includeWithinThreshold: true)->map(function (array $row) use ($minPipelineRatio, $maxDaysOpen) {
            $requisition = $row['requisition'];
            $filled = $requisition->filledOpeningsCount();
            $remaining = $requisition->remainingOpenings();
            $pipeline = $requisition->applications()->where('status', ApplicationStatus::Active)->count();
            $fulfilmentPercent = $requisition->openings > 0 ? round($filled / $requisition->openings * 100, 1) : 0.0;

            $isCritical = $remaining > 0 && ($row['ageing_days'] > $maxDaysOpen || $pipeline === 0);
            $isAtRisk = ! $isCritical && $remaining > 0 && (
                $row['is_overdue']
                || $pipeline < $remaining * $minPipelineRatio
            );

            return [
                'requisition' => $requisition,
                'required' => $requisition->openings,
                'filled' => $filled,
                'remaining' => $remaining,
                'fulfilment_percent' => $fulfilmentPercent,
                'pipeline' => $pipeline,
                'ageing_days' => $row['ageing_days'],
                'is_overdue' => $row['is_overdue'],
                'risk' => $isCritical ? 'critical' : ($isAtRisk ? 'at_risk' : 'on_track'),
            ];
        })->values();
    }

    /**
     * Interview completion/no-show/selection rates for a period, plus an interviewer-wise
     * breakdown (Section 16). Recruiter-wise/position-wise breakdowns are already covered by
     * conversionBreakdown('recruiter'|'requisition') — not duplicated here.
     *
     * @return array{scheduled: int, completed: int, no_show: int, cancelled: int, rescheduled: int, feedback_pending: int, completion_percent: float|null, no_show_percent: float|null, selection_percent: float|null, by_interviewer: Collection<int, array{interviewer: string, scheduled: int, completed: int, no_show: int, no_show_percent: float|null, selected: int}>, by_round: Collection<int, array{round: int, scheduled: int, completed: int, no_show: int, selected: int}>}
     */
    public function interviewAnalytics(CarbonInterface $start, CarbonInterface $end, ?User $user = null): array
    {
        $interviews = $this->scopedInterviews($start, $end, $user)
            ->with('interviewer:id,first_name,last_name')
            ->get(['id', 'interviewer_id', 'round_number', 'status', 'result', 'scheduled_at']);

        $byRound = $interviews
            ->groupBy(fn (Interview $i) => (int) $i->round_number)
            ->map(fn (Collection $group, int $round) => [
                'round' => $round,
                'scheduled' => $group->count(),
                'completed' => $group->where('status', InterviewStatus::Completed)->count(),
                'no_show' => $group->where('status', InterviewStatus::NoShow)->count(),
                'selected' => $group->where('result', InterviewResult::Selected)->count(),
            ])
            ->sortKeys()
            ->values();

        $scheduled = $interviews->count();
        $completed = $interviews->where('status', InterviewStatus::Completed)->count();
        $selected = $interviews->where('result', InterviewResult::Selected)->count();

        $byInterviewer = $interviews
            ->groupBy(fn (Interview $i) => $i->interviewer?->fullName() ?? 'Unassigned')
            ->map(function (Collection $group, string $name) {
                $groupScheduled = $group->count();
                $groupNoShow = $group->where('status', InterviewStatus::NoShow)->count();

                return [
                    'interviewer' => $name,
                    'scheduled' => $groupScheduled,
                    'completed' => $group->where('status', InterviewStatus::Completed)->count(),
                    'no_show' => $groupNoShow,
                    'no_show_percent' => $groupScheduled > 0 ? round($groupNoShow / $groupScheduled * 100, 1) : null,
                    'selected' => $group->where('result', InterviewResult::Selected)->count(),
                ];
            })
            ->values();

        return [
            'scheduled' => $scheduled,
            'completed' => $completed,
            'no_show' => $interviews->where('status', InterviewStatus::NoShow)->count(),
            'cancelled' => $interviews->where('status', InterviewStatus::Cancelled)->count(),
            'rescheduled' => $interviews->where('status', InterviewStatus::Rescheduled)->count(),
            'feedback_pending' => $interviews->where('status', InterviewStatus::Completed)->whereNull('result')->count(),
            'completion_percent' => $scheduled > 0 ? round($completed / $scheduled * 100, 1) : null,
            'no_show_percent' => $scheduled > 0 ? round($interviews->where('status', InterviewStatus::NoShow)->count() / $scheduled * 100, 1) : null,
            'selection_percent' => $completed > 0 ? round($selected / $completed * 100, 1) : null,
            'by_interviewer' => $byInterviewer,
            'by_round' => $byRound,
        ];
    }

    /**
     * Offer pipeline totals for a period (Section 18). `generated`/`acceptance_percent`/averages are
     * over offers dated in the period; `released` counts offers that actually reached Released in the
     * period (from offer_status_histories), and `released_acceptance_percent` is how many of those
     * released offers are now Accepted. `average_days_selection_to_offer` measures from the
     * application's first Selected stage change to the offer date.
     *
     * @return array{generated: int, released: int, accepted: int, rejected: int, pending: int, expired: int, withdrawn: int, acceptance_percent: float|null, released_acceptance_percent: float|null, average_offered_ctc: float|null, average_days_selection_to_offer: float|null}
     */
    public function offerAnalytics(CarbonInterface $start, CarbonInterface $end, ?User $user = null): array
    {
        $visibleIds = $user !== null ? $this->hierarchy->visibleEmployeeIdsFor($user) : null;

        $offers = Offer::query()
            ->whereBetween('offer_date', [$start->toDateString(), $end->toDateString()])
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)))
            ->get(['id', 'candidate_application_id', 'status', 'offered_ctc', 'offer_date']);

        $accepted = $offers->where('status', OfferStatus::Accepted)->count();
        $rejected = $offers->where('status', OfferStatus::Rejected)->count();
        $decided = $accepted + $rejected;

        $releasedOfferIds = OfferStatusHistory::query()
            ->where('to_status', OfferStatus::Released)
            ->whereBetween('created_at', [$start, $end])
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('offer.candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)))
            ->distinct()
            ->pluck('offer_id');

        $releasedAccepted = $releasedOfferIds->isEmpty()
            ? 0
            : Offer::query()->whereIn('id', $releasedOfferIds)->where('status', OfferStatus::Accepted)->count();

        $ctcValues = $offers->pluck('offered_ctc')->filter(fn ($ctc) => $ctc !== null)->map(fn ($ctc) => (float) $ctc);

        $selectedAt = CandidateStageHistory::query()
            ->whereIn('candidate_application_id', $offers->pluck('candidate_application_id')->unique())
            ->where('new_stage', CandidateStage::Selected)
            ->orderBy('created_at')
            ->get(['candidate_application_id', 'created_at'])
            ->unique('candidate_application_id')
            ->mapWithKeys(fn (CandidateStageHistory $h) => [$h->candidate_application_id => $h->created_at]);

        $selectionToOfferDays = $offers
            ->map(function (Offer $offer) use ($selectedAt): ?int {
                $selected = $selectedAt->get($offer->candidate_application_id);

                return $selected === null || $offer->offer_date === null
                    ? null
                    : (int) $selected->copy()->startOfDay()->diffInDays($offer->offer_date->copy()->startOfDay(), false);
            })
            ->filter(fn (?int $days) => $days !== null && $days >= 0);

        return [
            'released' => $releasedOfferIds->count(),
            'released_acceptance_percent' => $releasedOfferIds->isNotEmpty() ? round($releasedAccepted / $releasedOfferIds->count() * 100, 1) : null,
            'average_offered_ctc' => $ctcValues->isNotEmpty() ? round($ctcValues->avg(), 2) : null,
            'average_days_selection_to_offer' => $selectionToOfferDays->isNotEmpty() ? round($selectionToOfferDays->avg(), 1) : null,
            'generated' => $offers->count(),
            'accepted' => $accepted,
            'rejected' => $rejected,
            'pending' => $offers->whereIn('status', [OfferStatus::Initiated, OfferStatus::Released])->count(),
            'expired' => $offers->where('status', OfferStatus::Expired)->count(),
            'withdrawn' => $offers->where('status', OfferStatus::Withdrawn)->count(),
            'acceptance_percent' => $decided > 0 ? round($accepted / $decided * 100, 1) : null,
        ];
    }

    /**
     * Joining pipeline totals plus the near-term joining schedule (Section 19). Two conversions:
     * `joining_percent` is Selection -> Joining (joined ÷ selected in the period), and
     * `offer_to_join_percent` is Offer Accepted -> Joined (of offers accepted in the period, how many
     * of those applications have actually joined).
     *
     * @return array{selected: int, offered: int, accepted: int, accepted_joined: int, joined: int, no_show: int, dropout: int, joining_percent: float|null, offer_to_join_percent: float|null, today: int, tomorrow: int, next_7_days: int}
     */
    public function joiningAnalytics(CarbonInterface $start, CarbonInterface $end, ?User $user = null): array
    {
        $visibleIds = $user !== null ? $this->hierarchy->visibleEmployeeIdsFor($user) : null;

        $selected = CandidateStageHistory::query()
            ->where('new_stage', CandidateStage::Selected)
            ->whereBetween('created_at', [$start, $end])
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)))
            ->distinct('candidate_application_id')
            ->count('candidate_application_id');

        $acceptedApplicationIds = Offer::query()
            ->where('status', OfferStatus::Accepted)
            ->whereBetween('accepted_at', [$start, $end])
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)))
            ->pluck('candidate_application_id');

        $accepted = $acceptedApplicationIds->count();

        $acceptedJoined = $acceptedApplicationIds->isEmpty()
            ? 0
            : CandidateJoining::query()
                ->whereIn('candidate_application_id', $acceptedApplicationIds->unique())
                ->where('status', JoiningStatus::Joined)
                ->count();

        $offered = Offer::query()
            ->whereBetween('offer_date', [$start->toDateString(), $end->toDateString()])
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)))
            ->count();

        $joinings = CandidateJoining::query()
            ->whereBetween('expected_doj', [$start->toDateString(), $end->toDateString()])
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)))
            ->get(['id', 'status', 'expected_doj']);

        $joined = $joinings->where('status', JoiningStatus::Joined)->count();
        $today = now()->startOfDay();

        $upcomingBase = CandidateJoining::query()
            ->whereIn('status', [JoiningStatus::Expected, JoiningStatus::Confirmed])
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)));

        return [
            'selected' => $selected,
            'offered' => $offered,
            'accepted' => $accepted,
            'accepted_joined' => $acceptedJoined,
            'joined' => $joined,
            'no_show' => $joinings->where('status', JoiningStatus::NoShow)->count(),
            'dropout' => $joinings->where('status', JoiningStatus::Dropout)->count(),
            'joining_percent' => $selected > 0 ? round($joined / $selected * 100, 1) : null,
            'offer_to_join_percent' => $accepted > 0 ? round($acceptedJoined / $accepted * 100, 1) : null,
            'today' => (clone $upcomingBase)->whereDate('expected_doj', $today->toDateString())->count(),
            'tomorrow' => (clone $upcomingBase)->whereDate('expected_doj', $today->copy()->addDay()->toDateString())->count(),
            'next_7_days' => (clone $upcomingBase)->whereBetween('expected_doj', [$today->toDateString(), $today->copy()->addDays(7)->toDateString()])->count(),
        ];
    }

    /**
     * Pending (non-Joined) joinings flagged yellow/red by CandidateJoining::riskLevel() — never
     * reimplemented here, always delegated to the model (Section 19).
     *
     * @return Collection<int, array{joining: CandidateJoining, risk: string}>
     */
    public function joiningRisks(?User $user = null): Collection
    {
        $visibleIds = $user !== null ? $this->hierarchy->visibleEmployeeIdsFor($user) : null;

        return CandidateJoining::query()
            ->whereNotIn('status', [JoiningStatus::Joined])
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)))
            ->with(['candidateApplication.candidate:id,full_name'])
            ->get()
            ->map(fn (CandidateJoining $joining) => ['joining' => $joining, 'risk' => $joining->riskLevel()])
            ->filter(fn (array $row) => in_array($row['risk'], ['yellow', 'red'], true))
            ->values();
    }

    /**
     * @return Builder<Interview>
     */
    private function scopedInterviews(CarbonInterface $start, CarbonInterface $end, ?User $user): Builder
    {
        $visibleIds = $user !== null ? $this->hierarchy->visibleEmployeeIdsFor($user) : null;

        return Interview::query()
            ->whereBetween('scheduled_at', [$start, $end])
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)));
    }
}
