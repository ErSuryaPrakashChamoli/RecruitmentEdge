<?php

namespace App\Policies;

use App\Models\RecruitmentRejectionReason;
use App\Models\User;

/**
 * Rejection reasons are shared reference data (no hierarchy scoping) — managing them is gated by
 * the `settings.manage` permission.
 */
class RecruitmentRejectionReasonPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RecruitmentRejectionReason $recruitmentRejectionReason): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->can('settings.manage');
    }

    public function update(User $user, RecruitmentRejectionReason $recruitmentRejectionReason): bool
    {
        return $user->can('settings.manage');
    }

    public function delete(User $user, RecruitmentRejectionReason $recruitmentRejectionReason): bool
    {
        return $user->can('settings.manage');
    }

    public function restore(User $user, RecruitmentRejectionReason $recruitmentRejectionReason): bool
    {
        return $user->can('settings.manage');
    }

    public function forceDelete(User $user, RecruitmentRejectionReason $recruitmentRejectionReason): bool
    {
        return $user->can('settings.manage');
    }
}
