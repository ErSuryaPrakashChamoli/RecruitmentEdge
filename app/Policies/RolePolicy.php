<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

/**
 * Roles and their permission sets are gated entirely by `roles.manage` — misconfiguring this is
 * an org-wide authorization risk, so it is not delegated by hierarchy.
 *
 * Phase 8.4: nobody edits or deletes a role they hold, and a protected role (identified by its
 * immutable key, never its name) is never deleted. RoleAssignmentService enforces the same rules
 * for every path; this policy keeps the actions out of sight.
 */
class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('roles.manage');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->can('roles.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('roles.manage');
    }

    public function update(User $user, Role $role): bool
    {
        return $user->can('roles.manage') && ! $user->hasRole($role);
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->can('roles.manage') && ! $role->is_protected && ! $user->hasRole($role);
    }
}
