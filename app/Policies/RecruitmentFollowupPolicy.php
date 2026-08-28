<?php

namespace App\Policies;

use App\Models\RecruitmentFollowup;
use App\Models\User;
use App\Services\HierarchyService;

class RecruitmentFollowupPolicy
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function viewAny(User $user): bool
    {
        return $user->can('followups.manage');
    }

    public function view(User $user, RecruitmentFollowup $recruitmentFollowup): bool
    {
        return $user->can('followups.manage') && $this->hierarchy->canView($user, $recruitmentFollowup->recruiter);
    }

    public function create(User $user): bool
    {
        return $user->can('followups.manage');
    }

    public function update(User $user, RecruitmentFollowup $recruitmentFollowup): bool
    {
        return $user->can('followups.manage') && $this->hierarchy->canView($user, $recruitmentFollowup->recruiter);
    }

    public function delete(User $user, RecruitmentFollowup $recruitmentFollowup): bool
    {
        return $user->can('followups.manage') && $this->hierarchy->canView($user, $recruitmentFollowup->recruiter);
    }
}
