<?php

namespace App\Services;

use App\Enums\ActivityOutcome;
use App\Enums\ActivityType;
use App\Enums\CandidateStage;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\TargetMetric;
use App\Enums\TargetPeriodType;
use App\Models\Candidate;
use App\Models\CandidateJoining;
use App\Models\CandidateStageHistory;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\RecruitmentDailyActivity;
use App\Services\Metrics\MetricPeriod;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Computes each recruiter's actual achievement per target metric, reading only from authoritative
 * fact tables (candidates, recruitment_daily_activities, candidate_stage_histories, interviews,
 * offers, candidate_joinings) — never from `recruitment_manual_activities` (Section 46: the
 * engine must not depend on manually entered numbers when real recruitment records exist).
 */
class RecruiterDailyMetricsService
{
    public function __construct(private readonly TargetResolutionService $targets) {}

    /**
     * One recruiter's actual for one metric over a range of calendar days (Phase 8.5: business
     * timezone days, genuine stage entries only — see actualsFor()).
     */
    public function actualFor(Employee $recruiter, TargetMetric $metric, CarbonInterface $start, CarbonInterface $end): int
    {
        return $this->actualsFor([$recruiter->id], $metric, MetricPeriod::between($start, $end))[$recruiter->id] ?? 0;
    }

    /**
     * Actuals for many recruiters at once, keyed by employee id (recruiters with nothing are absent).
     *
     * Attribution (Phase 8.5 D8): activity metrics count the person who did it — profiles by
     * candidates.created_by, calls by the activity's recruiter, stage metrics by who made the move;
     * outcome metrics (interviews, offers, joining) count the application's current owner. Stage
     * metrics count distinct applications genuinely entering the stage (DF-9): a rejection, dropout,
     * hold, reactivation or requisition move is never a stage reached.
     *
     * @param  iterable<int>  $recruiterIds
     * @return array<int, int>
     */
    public function actualsFor(iterable $recruiterIds, TargetMetric $metric, MetricPeriod $period): array
    {
        $ids = collect($recruiterIds)->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $counts = match ($metric) {
            TargetMetric::ProfilesSourced => $period->whereTimestampColumn(Candidate::query(), 'created_at')
                ->whereIn('created_by', $ids)
                ->selectRaw('created_by as recruiter_id, count(*) as total')
                ->groupBy('created_by'),
            TargetMetric::Calls => $this->activityCounts($ids, ActivityType::Call, $period),
            TargetMetric::ConnectedCalls => $this->activityCounts($ids, ActivityType::Call, $period, ActivityOutcome::Connected),
            TargetMetric::InterestedCandidates => $this->stageReachedCounts($ids, CandidateStage::Interested, $period),
            TargetMetric::Screening => $this->stageReachedCounts($ids, CandidateStage::Screened, $period),
            TargetMetric::Shortlisted => $this->stageReachedCounts($ids, CandidateStage::Shortlisted, $period),
            TargetMetric::Selections => $this->stageReachedCounts($ids, CandidateStage::Selected, $period),
            TargetMetric::Interviews => $period->whereTimestampColumn(Interview::query(), 'interviews.scheduled_at')
                ->join('candidate_applications', 'candidate_applications.id', '=', 'interviews.candidate_application_id')
                ->whereNull('candidate_applications.deleted_at')
                ->whereIn('candidate_applications.recruiter_id', $ids)
                ->where('interviews.status', InterviewStatus::Completed->value)
                ->selectRaw('candidate_applications.recruiter_id as recruiter_id, count(*) as total')
                ->groupBy('candidate_applications.recruiter_id'),
            TargetMetric::Offers => $period->whereDateColumn(Offer::query(), 'offers.offer_date')
                ->join('candidate_applications', 'candidate_applications.id', '=', 'offers.candidate_application_id')
                ->whereNull('candidate_applications.deleted_at')
                ->whereIn('candidate_applications.recruiter_id', $ids)
                ->selectRaw('candidate_applications.recruiter_id as recruiter_id, count(*) as total')
                ->groupBy('candidate_applications.recruiter_id'),
            TargetMetric::Joining => $period->whereDateColumn(CandidateJoining::query(), 'candidate_joinings.actual_doj')
                ->join('candidate_applications', 'candidate_applications.id', '=', 'candidate_joinings.candidate_application_id')
                ->whereNull('candidate_applications.deleted_at')
                ->whereIn('candidate_applications.recruiter_id', $ids)
                ->where('candidate_joinings.status', JoiningStatus::Joined->value)
                ->selectRaw('candidate_applications.recruiter_id as recruiter_id, count(*) as total')
                ->groupBy('candidate_applications.recruiter_id'),
        };

        return $counts->toBase()->pluck('total', 'recruiter_id')->mapWithKeys(fn ($total, $id) => [(int) $id => (int) $total])->all();
    }

    /**
     * Target, actual, achievement %, and gap for every metric over the given range (defaults to a
     * single day) — the "Target / Actual / Achievement % / Gap" view from Section 20.
     *
     * @return Collection<int, array{metric: TargetMetric, target: int|null, actual: int, achievement: float|null, gap: int|null}>
     */
    public function accountabilityFor(
        Employee $recruiter,
        CarbonInterface $start,
        ?CarbonInterface $end = null,
        TargetPeriodType $periodType = TargetPeriodType::Daily,
    ): Collection {
        $end ??= $start;

        return collect(TargetMetric::cases())->map(function (TargetMetric $metric) use ($recruiter, $start, $end, $periodType): array {
            // DF-1 (Phase 8.5): the target for the whole range (prorated like the Performance Engine),
            // never a single day's target set against the range's actual.
            $target = $start->isSameDay($end) ? $this->targets->resolve($recruiter, $metric, $start, $periodType) : $this->targets->resolveForRange($recruiter, $metric, $start, $end);
            $actual = $this->actualFor($recruiter, $metric, $start, $end);

            return [
                'metric' => $metric,
                'target' => $target,
                'actual' => $actual,
                'achievement' => ($target !== null && $target > 0) ? round($actual / $target * 100, 1) : null,
                'gap' => $target !== null ? $actual - $target : null,
            ];
        });
    }

    /**
     * @param  Collection<int, int>  $ids
     * @return Builder<RecruitmentDailyActivity>
     */
    private function activityCounts(Collection $ids, ActivityType $type, MetricPeriod $period, ?ActivityOutcome $outcome = null): Builder
    {
        return $period->whereTimestampColumn(RecruitmentDailyActivity::query(), 'activity_datetime')
            ->whereIn('recruiter_id', $ids)
            ->where('activity_type', $type)
            ->when($outcome !== null, fn ($q) => $q->where('outcome', $outcome))
            ->selectRaw('recruiter_id, count(*) as total')
            ->groupBy('recruiter_id');
    }

    /**
     * @param  Collection<int, int>  $ids
     * @return Builder<CandidateStageHistory>
     */
    private function stageReachedCounts(Collection $ids, CandidateStage $stage, MetricPeriod $period): Builder
    {
        return $period->whereTimestampColumn(CandidateStageHistory::query()->milestoneEntries(), 'candidate_stage_histories.created_at')
            ->whereHas('candidateApplication')
            ->whereIn('changed_by', $ids)
            ->where('new_stage', $stage)
            ->selectRaw('changed_by as recruiter_id, count(distinct candidate_application_id) as total')
            ->groupBy('changed_by');
    }
}
