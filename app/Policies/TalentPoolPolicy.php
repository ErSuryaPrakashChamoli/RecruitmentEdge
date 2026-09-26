<?php

namespace App\Policies;

use App\Enums\TalentPoolVisibility;
use App\Models\TalentPool;
use App\Models\User;
use App\Services\HierarchyService;

/**
 * Talent pool access combines permissions with the existing hierarchy (see TalentPool::scopeVisibleTo
 * for the matching list query):
 * - view: talent-pools.viewAny + the pool is visible (organization-wide, owned within the user's
 *   hierarchy, or a Team pool owned by someone in their management chain).
 * - update/archive/remove members: talent-pools.manage + the owner is the user or below them.
 * - add members: anyone who can view the pool may contribute to a shared (Team/Organization) pool;
 *   Private pools need update rights. Callers must separately check the user can view each
 *   candidate they add.
 */
class TalentPoolPolicy
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function viewAny(User $user): bool
    {
        return $user->can('talent-pools.viewAny');
    }

    public function view(User $user, TalentPool $talentPool): bool
    {
        return $user->can('talent-pools.viewAny') && $this->isVisible($user, $talentPool);
    }

    public function create(User $user): bool
    {
        return $user->can('talent-pools.manage');
    }

    public function update(User $user, TalentPool $talentPool): bool
    {
        return $user->can('talent-pools.manage') && $this->ownsOrManagesOwner($user, $talentPool);
    }

    public function archive(User $user, TalentPool $talentPool): bool
    {
        return $this->update($user, $talentPool);
    }

    public function addMembers(User $user, TalentPool $talentPool): bool
    {
        if (! $talentPool->isActive() || ! $this->view($user, $talentPool)) {
            return false;
        }

        return $talentPool->visibility !== TalentPoolVisibility::Private || $this->update($user, $talentPool);
    }

    public function removeMembers(User $user, TalentPool $talentPool): bool
    {
        return $this->update($user, $talentPool);
    }

    public function delete(User $user, TalentPool $talentPool): bool
    {
        return false;
    }

    private function isVisible(User $user, TalentPool $pool): bool
    {
        $visibleIds = $this->hierarchy->visibleEmployeeIdsFor($user);

        if ($visibleIds === null || $pool->visibility === TalentPoolVisibility::Organization) {
            return true;
        }

        if ($pool->owner_id !== null && $visibleIds->contains($pool->owner_id)) {
            return true;
        }

        return $pool->visibility === TalentPoolVisibility::Team
            && $pool->owner_id !== null
            && $user->employee_id !== null
            && $this->hierarchy->ancestorIdsOf($user->employee_id)->contains($pool->owner_id);
    }

    private function ownsOrManagesOwner(User $user, TalentPool $pool): bool
    {
        $visibleIds = $this->hierarchy->visibleEmployeeIdsFor($user);

        return $visibleIds === null || ($pool->owner_id !== null && $visibleIds->contains($pool->owner_id));
    }
}
