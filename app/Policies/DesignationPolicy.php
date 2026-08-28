<?php

namespace App\Policies;

use App\Models\Designation;
use App\Models\User;

/**
 * Designations are shared reference data (no hierarchy scoping) — managing them is gated by the
 * `settings.manage` permission.
 */
class DesignationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Designation $designation): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->can('settings.manage');
    }

    public function update(User $user, Designation $designation): bool
    {
        return $user->can('settings.manage');
    }

    public function delete(User $user, Designation $designation): bool
    {
        return $user->can('settings.manage');
    }

    public function restore(User $user, Designation $designation): bool
    {
        return $user->can('settings.manage');
    }

    public function forceDelete(User $user, Designation $designation): bool
    {
        return $user->can('settings.manage');
    }
}
