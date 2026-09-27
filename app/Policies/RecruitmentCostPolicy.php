<?php

namespace App\Policies;

use App\Models\RecruitmentCost;
use App\Models\User;

/**
 * Cost entries feed Cost-per-Hire reporting for everyone with performance visibility, but
 * data-entry is gated by `settings.manage` like other configuration/reference data.
 */
class RecruitmentCostPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('performance.view') || $user->can('settings.manage');
    }

    public function view(User $user, RecruitmentCost $recruitmentCost): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('settings.manage');
    }

    public function update(User $user, RecruitmentCost $recruitmentCost): bool
    {
        return $user->can('settings.manage');
    }

    public function delete(User $user, RecruitmentCost $recruitmentCost): bool
    {
        return $user->can('settings.manage');
    }

    /**
     * Bulk actions (Phase 8.6 discovery security guard): explicit, never Filament's missing-method
     * fallback. Each selected record is still checked against the per-record rule.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('settings.manage');
    }
}
