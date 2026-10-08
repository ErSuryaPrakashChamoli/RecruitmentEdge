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

    /**
     * Bulk actions (Phase 8.6 discovery security guard): explicit, never Filament's missing-method
     * fallback. Each selected record is still checked against the per-record rule.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('activities.log');
    }
}
