<?php

namespace App\Services\Identity;

use App\Enums\AccessState;
use App\Enums\EmployeeStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\Lifecycle\LifecycleGuard;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 8.4: the only writer of the reporting line (Employee.reports_to_id is guarded) — the
 * hierarchy is the access boundary, so moving someone decides who sees their work.
 *
 * - Needs hierarchy.reassign (the org chart) or users.manage (the employee form).
 * - Both the employee and the new manager must be inside the actor's hierarchy unless the actor has
 *   hierarchy.view-all — nobody can pull an outsider under themselves to gain visibility.
 * - Nobody moves themselves, and nobody moves a holder of a protected role they do not hold.
 * - The new manager must be a current employee; the cycle check runs under row locks, so two
 *   concurrent moves can never create a loop.
 * - Deleting an employee is refused while they have current direct reports or an active login;
 *   deletion is soft (history and attribution stay), and permanent deletion is not offered.
 */
class HierarchyIntegrityService
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function reassign(Employee $employee, ?int $managerId, User $actor): Employee
    {
        if (! $actor->can('hierarchy.reassign') && ! $actor->can('users.manage')) {
            throw new DomainException('Changing a reporting line needs hierarchy.reassign.');
        }

        if ((int) $employee->reports_to_id === (int) $managerId && ($employee->reports_to_id === null) === ($managerId === null)) {
            return $employee;
        }

        if ($actor->employee_id !== null && (int) $actor->employee_id === (int) $employee->id) {
            throw new DomainException('You cannot change your own reporting line.');
        }

        if (! $this->hierarchy->canView($actor, $employee)) {
            $this->refuse($employee, $actor, 'employee_out_of_scope', 'This employee is outside your hierarchy.');
        }

        $this->assertAssignableManager($managerId, $actor);
        $this->assertNotProtected($employee, $actor);

        return $this->place($employee, $managerId, $actor);
    }

    /**
     * Places an employee under a manager for a lifecycle operation that has already authorised it
     * (candidate conversion, rehire: the manager was checked with assertAssignableManager). Same
     * locking, cycle check and audit as a reassignment.
     */
    public function placeUnder(Employee $employee, int $managerId, User $actor): Employee
    {
        return $this->place($employee, $managerId, $actor);
    }

    private function place(Employee $employee, ?int $managerId, User $actor): Employee
    {
        return DB::transaction(function () use ($employee, $managerId, $actor): Employee {
            // Lock both rows in a fixed order, then re-check the tree: a concurrent move of the
            // other one waits here and then sees the updated closure table.
            $ids = collect([$employee->id, $managerId])->filter()->sort()->values();
            Employee::withTrashed()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();

            $locked = Employee::withTrashed()->whereKey($employee->id)->firstOrFail();
            $old = $locked->reports_to_id;

            if ($old === $managerId) {
                return $employee;
            }

            if ($managerId !== null && ($managerId === $locked->id || DB::table('employee_hierarchy')->where('tenant_id', $locked->tenant_id)->where('ancestor_id', $locked->id)->where('descendant_id', $managerId)->exists())) {
                $this->refuse($locked, $actor, 'cycle', "{$locked->fullName()} cannot report to someone in their own reporting line.");
            }

            LifecycleGuard::allow(fn () => $locked->forceFill(['reports_to_id' => $managerId])->save());

            AuditLog::record($locked, 'reporting_line_changed', ['reports_to_id' => $old], ['reports_to_id' => $managerId, 'by_user_id' => $actor->id]);
            Log::info('identity.hierarchy_reassigned', ['employee_id' => $locked->id, 'from' => $old, 'to' => $managerId, 'actor_id' => $actor->id]);
            StaffAccessService::invalidateDecisions();

            $employee->setRawAttributes($locked->getAttributes(), true);

            return $employee;
        });
    }

    /**
     * A manager someone may be placed under by $actor: a current employee inside the actor's
     * hierarchy. No manager at all (a new root) needs hierarchy.view-all.
     */
    public function assertAssignableManager(?int $managerId, User $actor): void
    {
        $visible = $this->hierarchy->visibleEmployeeIdsFor($actor);

        if ($managerId === null) {
            if ($visible !== null) {
                throw new DomainException('Only someone with organisation-wide visibility can leave an employee without a manager.');
            }

            return;
        }

        $manager = Employee::query()->find($managerId);

        if ($manager === null || $manager->status !== EmployeeStatus::Active) {
            throw new DomainException('The new manager must be a current (active) employee.');
        }

        if ($visible !== null && ! $visible->contains($manager->id)) {
            throw new DomainException('The new manager is outside your hierarchy.');
        }
    }

    public function delete(Employee $employee, User $actor): void
    {
        if (! $actor->can('delete', $employee)) {
            throw new DomainException('Deleting an employee needs users.manage for an employee in your hierarchy.');
        }

        if ($employee->directReports()->exists()) {
            throw new DomainException('This employee still has direct reports. Move them to another manager first.');
        }

        $login = User::query()->where('employee_id', $employee->id)->first();

        if ($login !== null && $login->access_status === AccessState::Active) {
            throw new DomainException('This employee still has an active login. Separate or deactivate them first.');
        }

        $employee->delete();
        Log::info('identity.employee_deleted', ['employee_id' => $employee->id, 'actor_id' => $actor->id]);
        StaffAccessService::invalidateDecisions();
    }

    public function restore(Employee $employee, User $actor): void
    {
        if (! $actor->can('restore', $employee)) {
            throw new DomainException('Restoring an employee needs users.manage for an employee in your hierarchy.');
        }

        // Restoring the record never restores access: a login stays in whatever state it is in.
        $employee->restore();
        Log::info('identity.employee_restored', ['employee_id' => $employee->id, 'actor_id' => $actor->id]);
        StaffAccessService::invalidateDecisions();
    }

    private function assertNotProtected(Employee $employee, User $actor): void
    {
        $login = User::query()->where('employee_id', $employee->id)->with('roles')->first();

        foreach ($login?->roles ?? [] as $role) {
            if ($role->is_protected && ! $actor->hasRole($role)) {
                $this->refuse($employee, $actor, 'protected_role', "Only a holder of the {$role->name} role can move this person.");
            }
        }
    }

    private function refuse(Employee $employee, User $actor, string $reason, string $message): never
    {
        Log::warning('identity.hierarchy_rejected', ['employee_id' => $employee->id, 'reason' => $reason, 'actor_id' => $actor->id]);

        throw new DomainException($message);
    }
}
