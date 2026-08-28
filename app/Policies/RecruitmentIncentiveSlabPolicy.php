<?php

namespace App\Policies;

use App\Models\RecruitmentIncentiveSlab;
use App\Models\User;

/**
 * Slabs belong to a rule and share its authorization — gated by `incentives.configureRules`.
 */
class RecruitmentIncentiveSlabPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('incentives.configureRules');
    }

    public function view(User $user, RecruitmentIncentiveSlab $recruitmentIncentiveSlab): bool
    {
        return $user->can('incentives.configureRules');
    }

    public function create(User $user): bool
    {
        return $user->can('incentives.configureRules');
    }

    public function update(User $user, RecruitmentIncentiveSlab $recruitmentIncentiveSlab): bool
    {
        return $user->can('incentives.configureRules');
    }

    public function delete(User $user, RecruitmentIncentiveSlab $recruitmentIncentiveSlab): bool
    {
        return $user->can('incentives.configureRules');
    }
}
