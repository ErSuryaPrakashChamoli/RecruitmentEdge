<?php

namespace App\Policies;

use App\Models\EmployeeSeparation;
use App\Models\User;
use App\Services\HierarchyService;

/**
 * Phase 8.2 minimal separation record. Viewing needs employees.separation.view, recording or
 * correcting needs employees.separation.manage — both limited to employees in the user's
 * hierarchy. Records are corrected (audited), never deleted.
 */
class EmployeeSeparationPolicy
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function viewAny(User $user): bool
    {
        return $user->can('employees.separation.view');
    }

    public function view(User $user, EmployeeSeparation $separation): bool
    {
        return $user->can('employees.separation.view') && $separation->employee !== null && $this->hierarchy->canView($user, $separation->employee);
    }

    public function create(User $user): bool
    {
        return $user->can('employees.separation.manage');
    }

    public function update(User $user, EmployeeSeparation $separation): bool
    {
        return $user->can('employees.separation.manage') && $separation->employee !== null && $this->hierarchy->canView($user, $separation->employee);
    }

    public function delete(User $user, EmployeeSeparation $separation): bool
    {
        return false;
    }
}
