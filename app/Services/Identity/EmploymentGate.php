<?php

namespace App\Services\Identity;

use App\Enums\AccessState;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 8.4: employment facts the access gate honours directly, so the gate fails closed even
 * before the access state has caught up — in the hours between a separation taking effect and
 * identity:enforce-separations applying it, the person already cannot sign in or act.
 */
class EmploymentGate
{
    public function __construct(private readonly EmployeeLifecycleService $lifecycle) {}

    /**
     * Whether employment facts alone must stop $user right now: their employee has a separation
     * that took effect but was not applied yet. The sole CHRO is never locked out this way (the
     * separation itself is refused and audited when it is applied).
     */
    public function blocks(User $user): bool
    {
        if ($user->employee_id === null) {
            return false;
        }

        if (! $this->lifecycle->dueSeparations()->where('employee_id', $user->employee_id)->exists()) {
            return false;
        }

        return ! $this->isSoleChroHolder($user);
    }

    /**
     * Whether $user is currently employed: no employee record (a system login), or an employee who
     * is Active, not deleted and without a due separation.
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
            && ! $this->lifecycle->dueSeparations()->where('employee_id', $employee->id)->exists();
    }

    /**
     * Deliberately not AuthorityGuard::effectiveChroIds() — that asks the gate itself.
     */
    private function isSoleChroHolder(User $user): bool
    {
        $chro = Role::byKey((string) config('identity.chro_role'));

        if ($chro === null || ! $user->roles()->whereKey($chro->getKey())->exists()) {
            return false;
        }

        return ! User::query()
            ->whereKeyNot($user->getKey())
            ->membersOfCurrentTenant(fn (Builder $membership) => $membership->where('status', AccessState::Active->value))
            ->whereHas('roles', fn (Builder $query) => $query->whereKey($chro->getKey()))
            ->exists();
    }
}
