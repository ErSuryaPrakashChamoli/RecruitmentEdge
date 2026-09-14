<?php

namespace App\Services;

use App\Enums\JoiningStatus;
use App\Models\CandidateJoining;
use App\Models\RecruitmentCost;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Section 34: Cost per Hire = Total Recruitment Cost / Number of Successful Joins, for a period
 * optionally scoped to one requisition, department, or source, and optionally to a viewer's
 * hierarchy (the trailing `$user` argument — null means organization-wide).
 *
 * Hierarchy scoping: joins count when the application's recruiter is visible to the user; costs
 * count only when attached to a requisition the user has a stake in (the same multi-column check
 * as RecruitmentRequisitionResource::getEloquentQuery()). Unattached org-wide overhead costs are
 * therefore only included for unrestricted (hierarchy.view-all) viewers.
 */
class CostPerHireService
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function totalCost(CarbonInterface $start, CarbonInterface $end, ?int $requisitionId = null, ?int $departmentId = null, ?int $sourceId = null, ?User $user = null): float
    {
        $visibleIds = $this->visibleIdsFor($user);

        return (float) RecruitmentCost::query()
            ->whereBetween('incurred_on', [$start->toDateString(), $end->toDateString()])
            ->when($requisitionId !== null, fn (Builder $q) => $q->where('requisition_id', $requisitionId))
            ->when($departmentId !== null, fn (Builder $q) => $q->where('department_id', $departmentId))
            ->when($sourceId !== null, fn (Builder $q) => $q->where('source_id', $sourceId))
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('requisition', fn (Builder $r) => $r->where(function (Builder $r2) use ($visibleIds): void {
                $r2->whereIn('reporting_manager_id', $visibleIds)
                    ->orWhereIn('hiring_manager_id', $visibleIds)
                    ->orWhereIn('assistant_manager_id', $visibleIds)
                    ->orWhereIn('manager_id', $visibleIds)
                    ->orWhereIn('vp_hr_id', $visibleIds)
                    ->orWhereIn('created_by', $visibleIds)
                    ->orWhereHas('recruiters', fn (Builder $e) => $e->whereIn('employees.id', $visibleIds));
            })))
            ->sum('amount');
    }

    public function successfulJoins(CarbonInterface $start, CarbonInterface $end, ?int $requisitionId = null, ?int $departmentId = null, ?int $sourceId = null, ?User $user = null): int
    {
        $visibleIds = $this->visibleIdsFor($user);

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
            ->when(
                $visibleIds !== null,
                fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)),
            )
            ->count();
    }

    /**
     * Null when there were no successful joins in the period — a zero cost-per-hire would be
     * misleading, not meaningful.
     */
    public function costPerHire(CarbonInterface $start, CarbonInterface $end, ?int $requisitionId = null, ?int $departmentId = null, ?int $sourceId = null, ?User $user = null): ?float
    {
        $joins = $this->successfulJoins($start, $end, $requisitionId, $departmentId, $sourceId, $user);

        if ($joins === 0) {
            return null;
        }

        return round($this->totalCost($start, $end, $requisitionId, $departmentId, $sourceId, $user) / $joins, 2);
    }

    /**
     * @return Collection<int, int>|null
     */
    private function visibleIdsFor(?User $user): ?Collection
    {
        return $user !== null ? $this->hierarchy->visibleEmployeeIdsFor($user) : null;
    }
}
