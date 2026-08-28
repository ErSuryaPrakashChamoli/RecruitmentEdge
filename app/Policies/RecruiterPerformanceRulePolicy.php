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
}
