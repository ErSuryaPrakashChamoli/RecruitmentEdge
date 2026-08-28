<?php

namespace App\Policies;

use App\Models\RecruitmentDailyTarget;
use App\Models\User;
use App\Services\HierarchyService;

/**
 * An employee-scoped target may be set by anyone with `targets.configure` whose hierarchy
 * includes that employee (e.g. a Manager setting targets for their own recruiters). A
 * department- or designation-scoped target affects people outside any one setter's team by
 * definition, so it requires `hierarchy.view-all` (CHRO).
 */
class RecruitmentDailyTargetPolicy
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function viewAny(User $user): bool
    {
        return $user->can('performance.view') || $user->can('targets.configure');
    }

    public function view(User $user, RecruitmentDailyTarget $recruitmentDailyTarget): bool
    {
        return $this->viewAny($user) && $this->isInScope($user, $recruitmentDailyTarget);
    }

    public function create(User $user): bool
    {
        return $user->can('targets.configure');
    }

    public function update(User $user, RecruitmentDailyTarget $recruitmentDailyTarget): bool
    {
        return $user->can('targets.configure') && $this->isInScope($user, $recruitmentDailyTarget);
    }

    public function delete(User $user, RecruitmentDailyTarget $recruitmentDailyTarget): bool
    {
        return $user->can('targets.configure') && $this->isInScope($user, $recruitmentDailyTarget);
    }

    private function isInScope(User $user, RecruitmentDailyTarget $target): bool
    {
        if ($user->can('hierarchy.view-all')) {
            return true;
        }

        if ($target->department_id !== null || $target->designation_id !== null) {
            return false;
        }

        return $target->employee !== null && $this->hierarchy->canView($user, $target->employee);
    }
}
