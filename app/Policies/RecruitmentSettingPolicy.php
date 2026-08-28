<?php

namespace App\Policies;

use App\Models\RecruitmentSetting;
use App\Models\User;

/**
 * Settings drive alert thresholds, retention windows, and other business rules — unlike other
 * reference data, even viewing them is gated by `settings.manage`.
 */
class RecruitmentSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('settings.manage');
    }

    public function view(User $user, RecruitmentSetting $recruitmentSetting): bool
    {
        return $user->can('settings.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('settings.manage');
    }

    public function update(User $user, RecruitmentSetting $recruitmentSetting): bool
    {
        return $user->can('settings.manage');
    }

    public function delete(User $user, RecruitmentSetting $recruitmentSetting): bool
    {
        return $user->can('settings.manage');
    }
}
