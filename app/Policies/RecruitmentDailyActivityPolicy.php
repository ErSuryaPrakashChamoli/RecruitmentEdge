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

    /**
     * Bulk actions (Phase 8.6 discovery security guard): explicit, never Filament's missing-method
     * fallback. Each selected record is still checked against the per-record rule.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('activities.log');
    }
}
