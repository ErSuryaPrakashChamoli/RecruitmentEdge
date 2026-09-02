<?php

namespace App\Services;

use App\Enums\JoiningStatus;
use App\Models\CandidateJoining;
use App\Models\RecruitmentCost;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Section 34: Cost per Hire = Total Recruitment Cost / Number of Successful Joins, for a period
 * optionally scoped to one requisition, department, or source.
 */
class CostPerHireService
{
    public function totalCost(CarbonInterface $start, CarbonInterface $end, ?int $requisitionId = null, ?int $departmentId = null, ?int $sourceId = null): float
    {
        return (float) RecruitmentCost::query()
            ->whereBetween('incurred_on', [$start->toDateString(), $end->toDateString()])
            ->when($requisitionId !== null, fn (Builder $q) => $q->where('requisition_id', $requisitionId))
            ->when($departmentId !== null, fn (Builder $q) => $q->where('department_id', $departmentId))
            ->when($sourceId !== null, fn (Builder $q) => $q->where('source_id', $sourceId))
            ->sum('amount');
    }

    public function successfulJoins(CarbonInterface $start, CarbonInterface $end, ?int $requisitionId = null, ?int $departmentId = null, ?int $sourceId = null): int
    {
        return CandidateJoining::query()
            ->where('status', JoiningStatus::Joined)
            ->whereBetween('actual_doj', [$start->toDateString(), $end->toDateString()])
            ->when(
                $requisitionId !== null || $departmentId !== null,
                fn (Builder $q) => $q->whereHas('candidateApplication.requisition', function (Builder $r) use ($requisitionId, $departmentId): void {
                    $r->when($requisitionId !== null, fn (Builder $q2) => $q2->where('id', $requisitionId))
                        ->when($departmentId !== null, fn (Builder $q2) => $q2->where('department_id', $departmentId));
                }),
            )
            ->when(
                $sourceId !== null,
                fn (Builder $q) => $q->whereHas('candidateApplication.candidate', fn (Builder $c) => $c->where('source_id', $sourceId)),
            )
            ->count();
    }

    /**
     * Null when there were no successful joins in the period — a zero cost-per-hire would be
     * misleading, not meaningful.
     */
    public function costPerHire(CarbonInterface $start, CarbonInterface $end, ?int $requisitionId = null, ?int $departmentId = null, ?int $sourceId = null): ?float
    {
        $joins = $this->successfulJoins($start, $end, $requisitionId, $departmentId, $sourceId);

        if ($joins === 0) {
            return null;
        }

        return round($this->totalCost($start, $end, $requisitionId, $departmentId, $sourceId) / $joins, 2);
    }
}
