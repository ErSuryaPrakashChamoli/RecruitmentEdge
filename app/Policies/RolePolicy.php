<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Services\Tenancy\TenantContext;

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
        return $user->can('roles.manage') && self::inCurrentTenant($role);
    }

    public function create(User $user): bool
    {
        return $user->can('roles.manage');
    }

    public function update(User $user, Role $role): bool
    {
        return $user->can('roles.manage') && self::inCurrentTenant($role) && ! $user->hasRole($role);
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->can('roles.manage') && self::inCurrentTenant($role) && ! $role->is_protected && ! $user->hasRole($role);
    }

    /**
     * SaaS-1: roles belong to one tenant; another tenant's role is never visible or editable.
     */
    private static function inCurrentTenant(Role $role): bool
    {
        return $role->tenant_id !== null && (int) $role->tenant_id === TenantContext::current()->id();
    }
}
