<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;

/**
 * Departments are shared reference data (no hierarchy scoping) — managing them is gated by the
 * `settings.manage` permission.
 */
class DepartmentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Department $department): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->can('settings.manage');
    }

    public function update(User $user, Department $department): bool
    {
        return $user->can('settings.manage');
    }

    public function delete(User $user, Department $department): bool
    {
        return $user->can('settings.manage');
    }

    public function restore(User $user, Department $department): bool
    {
        return $user->can('settings.manage');
    }

    public function forceDelete(User $user, Department $department): bool
    {
        return $user->can('settings.manage');
    }
}
