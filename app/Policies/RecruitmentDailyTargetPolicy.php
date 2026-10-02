<?php

namespace App\Policies;

use App\Models\RecruitmentDailyTarget;
use App\Models\User;

/**
 * An employee-scoped target may be set by anyone with `targets.configure` whose hierarchy
 * includes that employee (e.g. a Manager setting targets for their own recruiters). A
 * department- or designation-scoped target affects people outside any one setter's team by
 * definition, so it requires `hierarchy.view-all` (CHRO). The scope rule itself is
 * RecruitmentDailyTarget::visibleTo()/isVisibleTo() (Phase 8.9, P89-SEC-001), shared with the
 * resource query and RecruitmentTargetService.
 */
class RecruitmentDailyTargetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('performance.view') || $user->can('targets.configure');
    }

    public function view(User $user, RecruitmentDailyTarget $recruitmentDailyTarget): bool
    {
        return $this->viewAny($user) && $recruitmentDailyTarget->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return $user->can('targets.configure');
    }

    public function update(User $user, RecruitmentDailyTarget $recruitmentDailyTarget): bool
    {
        return $user->can('targets.configure') && $recruitmentDailyTarget->isVisibleTo($user);
    }

    public function delete(User $user, RecruitmentDailyTarget $recruitmentDailyTarget): bool
    {
        return $user->can('targets.configure') && $recruitmentDailyTarget->isVisibleTo($user);
    }

    /**
     * Bulk actions (Phase 8.6 discovery security guard): explicit, never Filament's missing-method
     * fallback. Phase 8.9 (P89-SEC-001): Filament checks only this ability for a bulk action unless
     * the action authorizes each record, so the table's DeleteBulkAction calls
     * authorizeIndividualRecords('delete') and deletes through RecruitmentTargetService, which
     * checks delete() again.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('targets.configure');
    }
}
