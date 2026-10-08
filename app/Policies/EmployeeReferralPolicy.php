<?php

namespace App\Policies;

use App\Models\EmployeeReferral;
use App\Models\User;

/**
 * Referrals are hierarchy-scoped (see EmployeeReferral::scopeVisibleTo for the matching list
 * query): an employee sees their own; managers see their team's; reviewers (referrals.review)
 * also see referrals against requisitions they can see and general referrals.
 */
class EmployeeReferralPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('referrals.submit') || $user->can('referrals.review');
    }

    public function view(User $user, EmployeeReferral $employeeReferral): bool
    {
        return $this->viewAny($user)
            && EmployeeReferral::query()->visibleTo($user)->whereKey($employeeReferral->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('referrals.submit') && $user->employee_id !== null;
    }

    /**
     * Reviewing (status changes, accepting into the pipeline, editing eligibility) — never your
     * own referral, so nobody approves their own bonus path.
     */
    public function review(User $user, EmployeeReferral $employeeReferral): bool
    {
        return $user->can('referrals.review')
            && $employeeReferral->referrer_id !== $user->employee_id
            && $this->view($user, $employeeReferral);
    }

    public function update(User $user, EmployeeReferral $employeeReferral): bool
    {
        return $this->review($user, $employeeReferral);
    }

    public function delete(User $user, EmployeeReferral $employeeReferral): bool
    {
        return false;
    }
}
