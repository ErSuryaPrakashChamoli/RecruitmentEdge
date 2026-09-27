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

    /**
     * Phase 8.6 (D8.6-015): a rule that has priced incentives is ended (effective_to), not deleted.
     */
    public function delete(User $user, RecruitmentIncentiveRule $recruitmentIncentiveRule): bool
    {
        return $user->can('incentives.configureRules') && ! $recruitmentIncentiveRule->isUsed();
    }

    /**
     * Bulk actions (Phase 8.6 discovery security guard): explicit, never Filament's missing-method
     * fallback. Each selected record is still checked against the per-record rule.
     */
    public function deleteAny(User $user): bool
    {
        return false;
    }
}
