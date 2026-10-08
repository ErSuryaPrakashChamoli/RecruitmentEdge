<?php

namespace App\Policies;

use App\Models\HiringRisk;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\HierarchyService;

/**
 * Risks follow requisition visibility (RecruitmentRequisition::visibleTo); risks with no
 * requisition (interviewer backlog, automation rule) are visible to users who see everyone or whose
 * hierarchy includes the risk owner. Lifecycle changes need intelligence.risks.manage.
 */
class HiringRiskPolicy
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function viewAny(User $user): bool
    {
        return $user->can('intelligence.view');
    }

    public function view(User $user, HiringRisk $risk): bool
    {
        if (! $user->can('intelligence.view')) {
            return false;
        }

        if ($risk->requisition_id !== null) {
            return RecruitmentRequisition::query()->visibleTo($user)->whereKey($risk->requisition_id)->exists();
        }

        return $user->can('hierarchy.view-all') || ($risk->owner !== null && $this->hierarchy->canView($user, $risk->owner));
    }

    public function manage(User $user, HiringRisk $risk): bool
    {
        return $user->can('intelligence.risks.manage') && $this->view($user, $risk);
    }

    public function update(User $user, HiringRisk $risk): bool
    {
        return false;
    }

    public function delete(User $user, HiringRisk $risk): bool
    {
        return false;
    }
}
