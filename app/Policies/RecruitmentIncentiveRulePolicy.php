<?php

namespace App\Policies;

use App\Models\RecruitmentIncentiveRule;
use App\Models\User;

/**
 * Incentive rules are global financial configuration (no hierarchy scoping) — gated entirely by
 * `incentives.configureRules`.
 */
class RecruitmentIncentiveRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('incentives.configureRules');
    }

    public function view(User $user, RecruitmentIncentiveRule $recruitmentIncentiveRule): bool
    {
        return $user->can('incentives.configureRules');
    }

    public function create(User $user): bool
    {
        return $user->can('incentives.configureRules');
    }

    public function update(User $user, RecruitmentIncentiveRule $recruitmentIncentiveRule): bool
    {
        return $user->can('incentives.configureRules');
    }

    public function delete(User $user, RecruitmentIncentiveRule $recruitmentIncentiveRule): bool
    {
        return $user->can('incentives.configureRules');
    }
}
