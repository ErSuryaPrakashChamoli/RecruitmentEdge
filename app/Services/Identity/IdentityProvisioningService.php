<?php

namespace App\Services\Identity;

use App\Enums\EmployeeStatus;
use App\Events\UserProvisioned;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\Lifecycle\LifecycleGuard;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 8.4: the only way a staff login is created or linked to an employee record (User.employee_id
 * is guarded — the link decides whose team a login sees). A login is linked only to a current
 * (active, not deleted) employee inside the administrator's hierarchy who has no other login, and
 * never by the person to themselves. Roles always go through RoleAssignmentService.
 */
class IdentityProvisioningService
{
    public function __construct(
        private readonly RoleAssignmentService $roles,
        private readonly AuthorityGuard $authority,
        private readonly HierarchyService $hierarchy,
    ) {}

    /**
     * Creates a staff login from the Users resource.
     *
     * @param  array{name: string, email: string, password: string, employee_id?: int|string|null, roles?: array<int, int|string>}  $data
     */
    public function createStaffUser(array $data, User $actor): User
    {
        if (! $actor->can('users.manage')) {
            throw new DomainException('Creating logins needs the users.manage permission.');
        }

        CredentialService::assertAcceptablePassword((string) $data['password']);
        $employee = filled($data['employee_id'] ?? null) ? $this->linkableEmployee((int) $data['employee_id'], $actor, null) : null;

        if ($employee === null && $this->hierarchy->visibleEmployeeIdsFor($actor) !== null) {
            throw new DomainException('A login without an employee record can only be created by someone with organisation-wide visibility.');
        }

        return DB::transaction(function () use ($data, $employee, $actor): User {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'employee_id' => $employee?->id,
            ]);

            $this->roles->syncUserRoles($user, $data['roles'] ?? [], $actor, newUser: true);

            AuditLog::record($user, 'user_provisioned', null, ['employee_id' => $employee?->id, 'source' => 'administrator', 'by_user_id' => $actor->id]);
            Log::info('identity.user_provisioned', ['user_id' => $user->id, 'employee_id' => $employee?->id, 'source' => 'administrator', 'actor_id' => $actor->id]);
            UserProvisioned::dispatch($user->id, $employee?->id, 'administrator', $actor->id);

            return $user;
        });
    }

    /**
     * Links $user to an employee record (or unlinks with null).
     */
    public function linkEmployee(User $user, ?int $employeeId, User $actor): User
    {
        if (! $actor->can('users.manage')) {
            throw new DomainException('Linking a login to an employee needs the users.manage permission.');
        }

        $this->authority->assertNotSelf($actor, $user, 'You cannot change which employee record your own login is linked to.');

        if ((int) $user->employee_id === (int) $employeeId) {
            return $user;
        }

        $this->authority->assertInScope($actor, $user);
        $employee = $employeeId !== null ? $this->linkableEmployee($employeeId, $actor, $user) : null;

        return DB::transaction(function () use ($user, $employee, $actor): User {
            $old = $user->employee_id;

            LifecycleGuard::allow(fn () => $user->forceFill(['employee_id' => $employee?->id])->save());

            AuditLog::record($user, 'employee_linked', ['employee_id' => $old], ['employee_id' => $employee?->id, 'by_user_id' => $actor->id]);
            Log::info('identity.employee_linked', ['user_id' => $user->id, 'from' => $old, 'to' => $employee?->id, 'actor_id' => $actor->id]);
            StaffAccessService::invalidateDecisions();

            return $user;
        });
    }

    /**
     * An employee a login may be linked to: current, in the actor's hierarchy, and without another
     * login.
     */
    private function linkableEmployee(int $employeeId, User $actor, ?User $for): Employee
    {
        $employee = Employee::query()->find($employeeId);

        if ($employee === null || $employee->status !== EmployeeStatus::Active) {
            throw new DomainException('A login can only be linked to a current (active) employee.');
        }

        if (! $this->hierarchy->canView($actor, $employee)) {
            throw new DomainException('This employee is outside your hierarchy.');
        }

        if (User::query()->where('employee_id', $employee->id)->when($for !== null, fn ($query) => $query->whereKeyNot($for->id))->exists()) {
            throw new DomainException('This employee already has a login.');
        }

        return $employee;
    }
}
