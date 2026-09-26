<?php

namespace App\Policies;

use App\Models\HiringOutcome;
use App\Models\RecruitmentRequisition;
use App\Models\User;

/**
 * Hiring outcomes follow requisition visibility. Correcting, voiding and confirming (each a new,
 * audited version) need outcomes.manage; outcomes are never edited or deleted in place.
 */
class HiringOutcomePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('outcomes.view');
    }

    public function view(User $user, HiringOutcome $outcome): bool
    {
        return $user->can('outcomes.view')
            && ($user->can('hierarchy.view-all')
                || ($outcome->requisition_id !== null && RecruitmentRequisition::query()->visibleTo($user)->whereKey($outcome->requisition_id)->exists()));
    }

    public function manage(User $user, HiringOutcome $outcome): bool
    {
        return $user->can('outcomes.manage') && $this->view($user, $outcome) && $outcome->is_current;
    }

    public function update(User $user, HiringOutcome $outcome): bool
    {
        return false;
    }

    public function delete(User $user, HiringOutcome $outcome): bool
    {
        return false;
    }
}
