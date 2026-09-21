<?php

namespace App\Policies;

use App\Models\Interviewer;
use App\Models\User;

/**
 * The interviewer list is shared reference data (no hierarchy scoping) maintained by administrators —
 * every ability is gated by the `settings.manage` permission.
 */
class InterviewerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('settings.manage');
    }

    public function view(User $user, Interviewer $interviewer): bool
    {
        return $user->can('settings.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('settings.manage');
    }

    public function update(User $user, Interviewer $interviewer): bool
    {
        return $user->can('settings.manage');
    }

    public function delete(User $user, Interviewer $interviewer): bool
    {
        return $user->can('settings.manage');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('settings.manage');
    }
}
