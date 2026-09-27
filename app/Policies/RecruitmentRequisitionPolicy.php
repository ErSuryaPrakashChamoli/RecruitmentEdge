<?php

namespace App\Policies;

use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\HierarchyService;

/**
 * Visibility follows the hierarchy of everyone with a stake in the requisition (assigned
 * recruiters, hiring/reporting manager, and the AM/Manager/VP HR named on it) — see
 * RecruitmentRequisition::involvedEmployeeIds(). This is defense in depth alongside
 * RecruitmentRequisitionResource::getEloquentQuery().
 */
class RecruitmentRequisitionPolicy
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function viewAny(User $user): bool
    {
        return $user->can('requisitions.viewAny');
    }

    public function view(User $user, RecruitmentRequisition $recruitmentRequisition): bool
    {
        return $user->can('requisitions.viewAny') && $this->isInScope($user, $recruitmentRequisition);
    }

    public function create(User $user): bool
    {
        return $user->can('requisitions.create');
    }

    public function update(User $user, RecruitmentRequisition $recruitmentRequisition): bool
    {
        return $user->can('requisitions.update') && $this->isInScope($user, $recruitmentRequisition);
    }

    public function approve(User $user, RecruitmentRequisition $recruitmentRequisition): bool
    {
        return $user->can('requisitions.approve') && $this->isInScope($user, $recruitmentRequisition);
    }

    public function delete(User $user, RecruitmentRequisition $recruitmentRequisition): bool
    {
        return $user->can('requisitions.update') && $this->isInScope($user, $recruitmentRequisition);
    }

    /**
     * Security containment (Phase 8.6 discovery): these methods must exist — Filament treats a
     * missing one as allowed, which let anyone who could delete a requisition also force-delete it
     * (cascading its pipeline snapshots, Role DNA and approval history). A requisition is never
     * force-deleted; a soft-deleted one may be restored by whoever may delete it; bulk actions are off.
     */
    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, RecruitmentRequisition $recruitmentRequisition): bool
    {
        return $this->delete($user, $recruitmentRequisition);
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, RecruitmentRequisition $recruitmentRequisition): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    private function isInScope(User $user, RecruitmentRequisition $requisition): bool
    {
        $visible = $this->hierarchy->visibleEmployeeIdsFor($user);

        if ($visible === null) {
            return true;
        }

        return $visible->intersect($requisition->involvedEmployeeIds())->isNotEmpty()
            || ($user->employee_id !== null && $requisition->created_by === $user->employee_id);
    }
}
