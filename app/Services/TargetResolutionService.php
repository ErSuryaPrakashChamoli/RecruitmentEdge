<?php

namespace App\Services;

use App\Enums\TargetMetric;
use App\Enums\TargetPeriodType;
use App\Models\Employee;
use App\Models\RecruitmentDailyTarget;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Resolves the applicable target for a recruiter + metric + date, most-specific-scope-wins:
 * a target set directly on the employee beats one set on their designation, which beats one set
 * on their department (Section 19 — targets are configurable, never hard-coded).
 *
 * Scope tiers are strict: a designation tier only matches designation-scoped rows (employee_id
 * null) for the recruiter's own designation, and a department tier only matches department-scoped
 * rows (employee_id and designation_id null). A tier is skipped entirely when the recruiter has no
 * designation/department, so a null attribute never matches other rows' null columns.
 */
class TargetResolutionService
{
    /**
     * Resolves the target configured for exactly one period type, as-is (no proration).
     */
    public function resolve(
        Employee $recruiter,
        TargetMetric $metric,
        CarbonInterface $date,
        TargetPeriodType $periodType = TargetPeriodType::Daily,
    ): ?int {
        foreach ($this->scopeTiers($recruiter) as $tier) {
            $target = $this->targetsInTier($tier, $metric, $date, [$periodType])->first();

            if ($target !== null) {
                return $target->target_value;
            }
        }

        return null;
    }

    /**
     * Resolves a target for an arbitrary date range (e.g. a month, for performance scoring).
     *
     * Rule, applied per scope tier (employee, then designation, then department — the first tier
     * with any target for the metric wins, regardless of its period type):
     *  1. If the range is exactly one natural period — a single day (Daily), a Monday–Sunday week
     *     (Weekly), or a whole calendar month (Monthly) — a target of that period type is used
     *     as-is.
     *  2. Otherwise the most specific (shortest) configured period is prorated over the range:
     *     Daily × days; Weekly × days / 7; Monthly × Σ(days in each calendar month overlapped /
     *     that month's length). The result is rounded to the nearest whole number, so a prorated
     *     share below 0.5 becomes 0 (which scoring treats as "no measurable quota").
     *
     * Targets are resolved as effective on the range's start date.
     */
    public function resolveForRange(Employee $recruiter, TargetMetric $metric, CarbonInterface $start, CarbonInterface $end): ?int
    {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->startOfDay();

        if ($end->lessThan($start)) {
            return null;
        }

        $exactPeriod = $this->exactPeriodOf($start, $end);
        $preference = collect([$exactPeriod, TargetPeriodType::Daily, TargetPeriodType::Weekly, TargetPeriodType::Monthly])
            ->filter()
            ->unique(fn (TargetPeriodType $periodType): string => $periodType->value)
            ->values()
            ->all();

        foreach ($this->scopeTiers($recruiter) as $tier) {
            $targets = $this->targetsInTier($tier, $metric, $start, $preference);

            foreach ($preference as $periodType) {
                $target = $targets->first(fn (RecruitmentDailyTarget $row): bool => $row->period_type === $periodType);

                if ($target === null) {
                    continue;
                }

                if ($periodType === $exactPeriod) {
                    return $target->target_value;
                }

                return (int) round($target->target_value * $this->periodsIn($periodType, $start, $end));
            }
        }

        return null;
    }

    /**
     * @return list<Closure(Builder<RecruitmentDailyTarget>): Builder<RecruitmentDailyTarget>>
     */
    private function scopeTiers(Employee $recruiter): array
    {
        $tiers = [
            fn (Builder $query): Builder => $query->where('employee_id', $recruiter->id),
        ];

        if ($recruiter->designation_id !== null) {
            $tiers[] = fn (Builder $query): Builder => $query
                ->whereNull('employee_id')
                ->where('designation_id', $recruiter->designation_id);
        }

        if ($recruiter->department_id !== null) {
            $tiers[] = fn (Builder $query): Builder => $query
                ->whereNull('employee_id')
                ->whereNull('designation_id')
                ->where('department_id', $recruiter->department_id);
        }

        return $tiers;
    }

    /**
     * @param  Closure(Builder<RecruitmentDailyTarget>): Builder<RecruitmentDailyTarget>  $tier
     * @param  array<int, TargetPeriodType>  $periodTypes
     * @return Collection<int, RecruitmentDailyTarget>
     */
    private function targetsInTier(Closure $tier, TargetMetric $metric, CarbonInterface $date, array $periodTypes): Collection
    {
        return $tier(RecruitmentDailyTarget::query())
            ->where('metric', $metric)
            ->whereIn('period_type', array_map(fn (TargetPeriodType $periodType): string => $periodType->value, $periodTypes))
            ->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();
    }

    private function exactPeriodOf(CarbonInterface $start, CarbonInterface $end): ?TargetPeriodType
    {
        return match (true) {
            $start->isSameDay($end) => TargetPeriodType::Daily,
            $start->isSameDay($start->copy()->startOfWeek(CarbonInterface::MONDAY))
                && $end->isSameDay($start->copy()->endOfWeek(CarbonInterface::SUNDAY)) => TargetPeriodType::Weekly,
            $start->isSameDay($start->copy()->startOfMonth())
                && $end->isSameDay($start->copy()->endOfMonth()) => TargetPeriodType::Monthly,
            default => null,
        };
    }

    /**
     * How many of the given period fit into the (inclusive, day-granular) range.
     */
    private function periodsIn(TargetPeriodType $periodType, CarbonInterface $start, CarbonInterface $end): float
    {
        $days = (int) $start->diffInDays($end) + 1;

        if ($periodType === TargetPeriodType::Daily) {
            return $days;
        }

        if ($periodType === TargetPeriodType::Weekly) {
            return $days / 7;
        }

        $months = 0.0;
        $cursor = $start->copy();

        while ($cursor->lessThanOrEqualTo($end)) {
            $monthEnd = $cursor->copy()->endOfMonth()->startOfDay();
            $segmentEnd = $monthEnd->lessThan($end) ? $monthEnd : $end->copy();

            $months += ((int) $cursor->diffInDays($segmentEnd) + 1) / $cursor->daysInMonth;
            $cursor = $segmentEnd->copy()->addDay();
        }

        return $months;
    }
}
