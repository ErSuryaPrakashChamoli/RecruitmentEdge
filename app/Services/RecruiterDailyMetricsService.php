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

    public function actualFor(Employee $recruiter, TargetMetric $metric, CarbonInterface $start, CarbonInterface $end): int
    {
        $range = [$start->copy()->startOfDay(), $end->copy()->endOfDay()];

        return match ($metric) {
            TargetMetric::ProfilesSourced => Candidate::query()
                ->where('created_by', $recruiter->id)
                ->whereBetween('created_at', $range)
                ->count(),
            TargetMetric::Calls => $this->activityCount($recruiter, ActivityType::Call, $range),
            TargetMetric::ConnectedCalls => $this->activityCount($recruiter, ActivityType::Call, $range, ActivityOutcome::Connected),
            TargetMetric::InterestedCandidates => $this->stageReachedCount($recruiter, CandidateStage::Interested, $range),
            TargetMetric::Screening => $this->stageReachedCount($recruiter, CandidateStage::Screened, $range),
            TargetMetric::Selections => $this->stageReachedCount($recruiter, CandidateStage::Selected, $range),
            TargetMetric::Interviews => Interview::query()
                ->whereHas('candidateApplication', fn (Builder $q) => $q->where('recruiter_id', $recruiter->id))
                ->where('status', InterviewStatus::Completed)
                ->whereBetween('scheduled_at', $range)
                ->count(),
            TargetMetric::Offers => Offer::query()
                ->whereHas('candidateApplication', fn (Builder $q) => $q->where('recruiter_id', $recruiter->id))
                ->whereBetween('offer_date', $range)
                ->count(),
            TargetMetric::Joining => CandidateJoining::query()
                ->whereHas('candidateApplication', fn (Builder $q) => $q->where('recruiter_id', $recruiter->id))
                ->where('status', JoiningStatus::Joined)
                ->whereBetween('actual_doj', $range)
                ->count(),
        };
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
            $target = $this->targets->resolve($recruiter, $metric, $start, $periodType);
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
     * @param  array{0: CarbonInterface, 1: CarbonInterface}  $range
     */
    private function activityCount(Employee $recruiter, ActivityType $type, array $range, ?ActivityOutcome $outcome = null): int
    {
        return RecruitmentDailyActivity::query()
            ->where('recruiter_id', $recruiter->id)
            ->where('activity_type', $type)
            ->when($outcome !== null, fn ($q) => $q->where('outcome', $outcome))
            ->whereBetween('activity_datetime', $range)
            ->count();
    }

    /**
     * @param  array{0: CarbonInterface, 1: CarbonInterface}  $range
     */
    private function stageReachedCount(Employee $recruiter, CandidateStage $stage, array $range): int
    {
        return CandidateStageHistory::query()
            ->where('changed_by', $recruiter->id)
            ->where('new_stage', $stage)
            ->whereBetween('created_at', $range)
            ->count();
    }
}
