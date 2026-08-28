<?php

namespace App\Policies;

use App\Models\RecruitmentDailyActivity;
use App\Models\User;
use App\Services\HierarchyService;

class RecruitmentDailyActivityPolicy
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function viewAny(User $user): bool
    {
        return $user->can('activities.log');
    }

    public function view(User $user, RecruitmentDailyActivity $recruitmentDailyActivity): bool
    {
        return $user->can('activities.log') && $this->hierarchy->canView($user, $recruitmentDailyActivity->recruiter);
    }

    public function create(User $user): bool
    {
        return $user->can('activities.log');
    }

    public function update(User $user, RecruitmentDailyActivity $recruitmentDailyActivity): bool
    {
        return $user->can('activities.log') && $this->hierarchy->canView($user, $recruitmentDailyActivity->recruiter);
    }

    public function delete(User $user, RecruitmentDailyActivity $recruitmentDailyActivity): bool
    {
        return $user->can('activities.log') && $this->hierarchy->canView($user, $recruitmentDailyActivity->recruiter);
    }
}
