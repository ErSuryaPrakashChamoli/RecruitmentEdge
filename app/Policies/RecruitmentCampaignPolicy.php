<?php

namespace App\Policies;

use App\Models\RecruitmentCampaign;
use App\Models\User;
use App\Services\HierarchyService;

/**
 * Campaigns are managed with campaigns.manage and visible when the owner is in the user's
 * hierarchy (or the user sees everything) — the same scope RecruitmentCampaignResource applies.
 */
class RecruitmentCampaignPolicy
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function viewAny(User $user): bool
    {
        return $user->can('campaigns.manage');
    }

    public function view(User $user, RecruitmentCampaign $recruitmentCampaign): bool
    {
        if (! $user->can('campaigns.manage')) {
            return false;
        }

        $visibleIds = $this->hierarchy->visibleEmployeeIdsFor($user);

        return $visibleIds === null || ($recruitmentCampaign->owner_id !== null && $visibleIds->contains($recruitmentCampaign->owner_id));
    }

    public function create(User $user): bool
    {
        return $user->can('campaigns.manage');
    }

    public function update(User $user, RecruitmentCampaign $recruitmentCampaign): bool
    {
        return $this->view($user, $recruitmentCampaign);
    }

    public function delete(User $user, RecruitmentCampaign $recruitmentCampaign): bool
    {
        return false;
    }
}
