<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;
use App\Services\HierarchyService;

/**
 * Employee visibility follows the reporting hierarchy (HierarchyService); creating, editing, or
 * removing employee master data is an HR-admin action gated by the `users.manage` permission.
 *
 * This is defense in depth alongside EmployeeResource::getEloquentQuery(): the resource query
 * keeps out-of-hierarchy employees out of lists, this policy blocks a direct `find($id)` on one
 * even if a user tampers with the URL.
 */
class EmployeePolicy
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Employee $employee): bool
    {
        return $this->hierarchy->canView($user, $employee);
    }

    public function create(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->can('users.manage') && $this->hierarchy->canView($user, $employee);
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $user->can('users.manage') && $this->hierarchy->canView($user, $employee);
    }

    public function restore(User $user, Employee $employee): bool
    {
        return $user->can('users.manage');
    }

    public function forceDelete(User $user, Employee $employee): bool
    {
        return $user->can('users.manage');
    }
}
