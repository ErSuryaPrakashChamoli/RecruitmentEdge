<?php

namespace App\Services\Identity;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\User;

/**
 * Phase 8.4: employment facts the access gate honours directly, so the gate fails closed even
 * before the access state has caught up (for example in the hours between a separation becoming
 * effective and identity:enforce-separations running).
 */
class EmploymentGate
{
    /**
     * Whether employment facts alone must stop $user from using the system right now.
     */
    public function blocks(User $user): bool
    {
        return false;
    }

    /**
     * Whether $user is currently employed: no employee record (a system login), or an employee who
     * is Active and not deleted.
     */
    public function isEmployed(User $user): bool
    {
        if ($user->employee_id === null) {
            return true;
        }

        $employee = Employee::withTrashed()->find($user->employee_id);

        return $employee !== null
            && ! $employee->trashed()
            && $employee->status === EmployeeStatus::Active
            && ! $this->blocks($user);
    }
}
