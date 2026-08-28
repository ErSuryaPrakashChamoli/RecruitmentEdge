<?php

namespace App\Services;

use App\Enums\CandidateStage;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\RequisitionStatus;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\CandidateSource;
use App\Models\CandidateStageHistory;
use App\Models\Interview;
use App\Models\RecruitmentRequisition;
use App\Models\RecruitmentSetting;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
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
     * Per-source funnel (Sourced -> Interviewed -> Selected -> Joined), for identifying the best
     * hiring sources (Section 33).
     *
     * @return Collection<int, array{source: CandidateSource, sourced: int, interviewed: int, selected: int, joined: int}>
     */
    public function sourceAnalytics(CarbonInterface $start, CarbonInterface $end): Collection
    {
        return CandidateSource::query()->get()->map(function (CandidateSource $source) use ($start, $end) {
            $candidateIds = Candidate::query()
                ->where('source_id', $source->id)
                ->whereBetween('created_at', [$start, $end])
                ->pluck('id');

            $applicationIds = CandidateApplication::query()->whereIn('candidate_id', $candidateIds)->pluck('id');

            return [
                'source' => $source,
                'sourced' => $candidateIds->count(),
                'interviewed' => Interview::query()
                    ->whereIn('candidate_application_id', $applicationIds)
                    ->where('status', InterviewStatus::Completed)
                    ->distinct('candidate_application_id')
                    ->count('candidate_application_id'),
                'selected' => CandidateStageHistory::query()
                    ->whereIn('candidate_application_id', $applicationIds)
                    ->where('new_stage', CandidateStage::Selected)
                    ->distinct('candidate_application_id')
                    ->count('candidate_application_id'),
                'joined' => CandidateJoining::query()
                    ->whereIn('candidate_application_id', $applicationIds)
                    ->where('status', JoiningStatus::Joined)
                    ->count(),
            ];
        });
    }

    /**
     * Every open/on-hold requisition with its age and whether it has crossed the configurable
     * `vacancy_ageing_alert_days` threshold (Section 36).
     *
     * @return Collection<int, array{requisition: RecruitmentRequisition, ageing_days: int, is_overdue: bool}>
     */
    public function vacancyAgeing(?User $user = null): Collection
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
            ])
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
}
