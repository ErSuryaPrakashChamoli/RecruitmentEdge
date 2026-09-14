<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Enums\MetricAccountability;
use App\Enums\TargetMetric;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\RecruiterPerformanceRule;
use App\Models\RecruiterPerformanceSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Computes a recruiter's composite performance score for a period as a weighted average of each
 * configured metric's achievement % (Section 21 — e.g. "30% Activity + 25% Interviews + ..." are
 * examples only; weights and which metrics count are entirely admin-configurable via
 * RecruiterPerformanceRule). A metric with no target configured for the recruiter simply doesn't
 * contribute, rather than dragging the score toward zero.
 */
class PerformanceEngine
{
    public function __construct(
        private readonly TargetResolutionService $targets,
        private readonly RecruiterDailyMetricsService $metrics,
    ) {}

    /**
     * @return array{score: float|null, breakdown: array<int, array{metric: string, weight: float, target: int|null, actual: int, achievement: float|null}>}
     */
    public function computeFor(Employee $recruiter, CarbonInterface $start, CarbonInterface $end): array
    {
        $rules = RecruiterPerformanceRule::query()
            ->where('effective_from', '<=', $start)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $start))
            ->get();

        $breakdown = [];
        $weightedSum = 0.0;
        $totalWeight = 0.0;

        foreach ($rules as $rule) {
            $target = $this->targets->resolveForRange($recruiter, $rule->metric, $start, $end);
            $actual = $this->metrics->actualFor($recruiter, $rule->metric, $start, $end);
            $achievement = ($target !== null && $target > 0) ? round($actual / $target * 100, 1) : null;
            $weight = (float) $rule->weightage;

            $breakdown[] = [
                'metric' => $rule->metric->value,
                'weight' => $weight,
                'target' => $target,
                'actual' => $actual,
                'achievement' => $achievement,
            ];

            if ($achievement !== null) {
                $weightedSum += $achievement * $weight;
                $totalWeight += $weight;
            }
        }

        return [
            'score' => $totalWeight > 0 ? round($weightedSum / $totalWeight, 2) : null,
            'breakdown' => $breakdown,
        ];
    }

    /**
     * The live composite score for a recruiter and period, or null when no configured rule has a
     * resolvable target for them.
     */
    public function compositeScoreFor(Employee $recruiter, CarbonInterface $start, CarbonInterface $end): ?float
    {
        return $this->computeFor($recruiter, $start, $end)['score'];
    }

    /**
     * Upserts the snapshot manually (rather than `updateOrCreate`) because `period_start`/
     * `period_end` are date-cast columns: Eloquent's date cast serializes to a full "Y-m-d H:i:s"
     * string for storage, so a plain string match on `toDateString()` never finds the existing
     * row. `whereDate()` compares only the date part at the SQL level and sidesteps that mismatch.
     */
    public function snapshotFor(Employee $recruiter, CarbonInterface $start, CarbonInterface $end): RecruiterPerformanceSnapshot
    {
        $result = $this->computeFor($recruiter, $start, $end);

        $attributes = [
            'score' => $result['score'],
            'breakdown' => $result['breakdown'],
            'computed_at' => now(),
        ];

        $snapshot = RecruiterPerformanceSnapshot::query()
            ->where('employee_id', $recruiter->id)
            ->whereDate('period_start', $start)
            ->whereDate('period_end', $end)
            ->first();

        if ($snapshot !== null) {
            $snapshot->update($attributes);

            return $snapshot;
        }

        return RecruiterPerformanceSnapshot::query()->create([
            'employee_id' => $recruiter->id,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            ...$attributes,
        ]);
    }

    /**
     * Snapshots every active recruiter (an active employee assigned as recruiter on at least one
     * application) for the period, optionally limited to a hierarchy-visible set of employee ids.
     *
     * @param  Collection<int, int>|null  $visibleEmployeeIds  null means no restriction
     * @return int the number of recruiters snapshotted
     */
    public function snapshotAllRecruiters(CarbonInterface $start, CarbonInterface $end, ?Collection $visibleEmployeeIds = null): int
    {
        $count = 0;

        $this->activeRecruitersQuery($visibleEmployeeIds)
            ->lazyById()
            ->each(function (Employee $recruiter) use ($start, $end, &$count): void {
                $this->snapshotFor($recruiter, $start, $end);
                $count++;
            });

        return $count;
    }

    /**
     * @param  Collection<int, int>|null  $visibleEmployeeIds
     * @return Builder<Employee>
     */
    public function activeRecruitersQuery(?Collection $visibleEmployeeIds = null): Builder
    {
        return Employee::query()
            ->where('status', EmployeeStatus::Active)
            ->whereIn('id', CandidateApplication::query()
                ->select('recruiter_id')
                ->whereNotNull('recruiter_id')
                ->when($visibleEmployeeIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleEmployeeIds)));
    }

    /**
     * Groups a breakdown into Controllable vs Influenced metrics (see TargetMetric::accountability())
     * with a met/measured count and average achievement per group. "Measured" means the metric had
     * a resolvable target; "met" means achievement reached 100%.
     *
     * @param  array<int, array{metric: string, weight?: float, target: int|null, actual: int, achievement: float|null}>  $breakdown
     * @return array<string, array{accountability: MetricAccountability, rows: list<array{metric: string, weight?: float, target: int|null, actual: int, achievement: float|null}>, measured: int, met: int, average_achievement: float|null}>
     */
    public static function summarizeByAccountability(array $breakdown): array
    {
        $summary = [];

        foreach (MetricAccountability::cases() as $accountability) {
            $rows = array_values(array_filter(
                $breakdown,
                fn (array $row): bool => TargetMetric::tryFrom((string) ($row['metric'] ?? ''))?->accountability() === $accountability,
            ));

            $achievements = array_values(array_filter(
                array_map(fn (array $row): ?float => isset($row['achievement']) ? (float) $row['achievement'] : null, $rows),
                fn (?float $achievement): bool => $achievement !== null,
            ));

            $summary[$accountability->value] = [
                'accountability' => $accountability,
                'rows' => $rows,
                'measured' => count($achievements),
                'met' => count(array_filter($achievements, fn (float $achievement): bool => $achievement >= 100)),
                'average_achievement' => $achievements === [] ? null : round(array_sum($achievements) / count($achievements), 1),
            ];
        }

        return $summary;
    }
}
