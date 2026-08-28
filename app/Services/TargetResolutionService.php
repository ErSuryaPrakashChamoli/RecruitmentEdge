<?php

namespace App\Services;

use App\Enums\TargetMetric;
use App\Enums\TargetPeriodType;
use App\Models\Employee;
use App\Models\RecruitmentDailyTarget;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves the applicable target for a recruiter + metric + date, most-specific-scope-wins:
 * a target set directly on the employee beats one set on their designation, which beats one set
 * on their department (Section 19 — targets are configurable, never hard-coded).
 */
class TargetResolutionService
{
    public function resolve(
        Employee $recruiter,
        TargetMetric $metric,
        CarbonInterface $date,
        TargetPeriodType $periodType = TargetPeriodType::Daily,
    ): ?int {
        $base = fn (): Builder => RecruitmentDailyTarget::query()
            ->where('metric', $metric)
            ->where('period_type', $periodType)
            ->where('effective_from', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date));

        $employeeTarget = $base()->where('employee_id', $recruiter->id)->latest('effective_from')->first();
        if ($employeeTarget !== null) {
            return $employeeTarget->target_value;
        }

        $designationTarget = $base()->where('designation_id', $recruiter->designation_id)->latest('effective_from')->first();
        if ($designationTarget !== null) {
            return $designationTarget->target_value;
        }

        $departmentTarget = $base()->where('department_id', $recruiter->department_id)->latest('effective_from')->first();

        return $departmentTarget?->target_value;
    }

    /**
     * Resolves a target for a multi-day period (e.g. a month, for performance scoring): a
     * configured Monthly target is used as-is; a configured Daily target is multiplied by the
     * number of days in the range, since it represents a per-day figure.
     */
    public function resolveForRange(Employee $recruiter, TargetMetric $metric, CarbonInterface $start, CarbonInterface $end): ?int
    {
        $monthly = $this->resolve($recruiter, $metric, $start, TargetPeriodType::Monthly);
        if ($monthly !== null) {
            return $monthly;
        }

        $daily = $this->resolve($recruiter, $metric, $start, TargetPeriodType::Daily);
        if ($daily === null) {
            return null;
        }

        return $daily * ($start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1);
    }
}
