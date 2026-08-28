<?php

namespace App\Policies;

use App\Models\RecruitmentManualActivity;
use App\Models\User;
use App\Services\HierarchyService;

class RecruitmentManualActivityPolicy
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function viewAny(User $user): bool
    {
        return $user->can('activities.log');
    }

    public function view(User $user, RecruitmentManualActivity $recruitmentManualActivity): bool
    {
        return $user->can('activities.log') && $this->hierarchy->canView($user, $recruitmentManualActivity->recruiter);
    }

    public function create(User $user): bool
    {
        return $user->can('activities.log');
    }

    public function update(User $user, RecruitmentManualActivity $recruitmentManualActivity): bool
    {
        return $user->can('activities.log') && $this->hierarchy->canView($user, $recruitmentManualActivity->recruiter);
    }

    public function delete(User $user, RecruitmentManualActivity $recruitmentManualActivity): bool
    {
        return $user->can('activities.log') && $this->hierarchy->canView($user, $recruitmentManualActivity->recruiter);
    }
}
