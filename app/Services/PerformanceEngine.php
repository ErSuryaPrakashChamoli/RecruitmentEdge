<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\RecruiterPerformanceRule;
use App\Models\RecruiterPerformanceSnapshot;
use Carbon\CarbonInterface;

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
}
