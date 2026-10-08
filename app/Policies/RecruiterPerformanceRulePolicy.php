<?php

namespace App\Policies;

use App\Models\RecruiterPerformanceRule;
use App\Models\User;

/**
 * Performance rules are global scoring configuration (no hierarchy scoping) — misconfiguring the
 * weightage affects every recruiter's score, so it's gated entirely by `performance.configure`.
 */
class RecruiterPerformanceRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('performance.configure');
    }

    public function view(User $user, RecruiterPerformanceRule $recruiterPerformanceRule): bool
    {
        return $user->can('performance.configure');
    }

    public function create(User $user): bool
    {
        return $user->can('performance.configure');
    }

    public function update(User $user, RecruiterPerformanceRule $recruiterPerformanceRule): bool
    {
        return $user->can('performance.configure');
    }

    public function delete(User $user, RecruiterPerformanceRule $recruiterPerformanceRule): bool
    {
        return $user->can('performance.configure');
    }

    /**
     * Bulk actions (Phase 8.6 discovery security guard): explicit, never Filament's missing-method
     * fallback. Each selected record is still checked against the per-record rule.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('performance.configure');
    }
}
