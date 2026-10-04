<?php

namespace App\Services\Identity;

use App\Enums\EmployeeStatus;
use App\Enums\TenantMembershipStatus;
use App\Events\UserProvisioned;
use App\Mail\StaffAccessInvitation;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\Lifecycle\LifecycleGuard;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

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
            $this->joinCurrentTenant($user, $employee);

            $this->roles->syncUserRoles($user, $data['roles'] ?? [], $actor, newUser: true);

            AuditLog::record($user, 'user_provisioned', null, ['employee_id' => $employee?->id, 'source' => 'administrator', 'by_user_id' => $actor->id]);
            Log::info('identity.user_provisioned', ['user_id' => $user->id, 'employee_id' => $employee?->id, 'source' => 'administrator', 'actor_id' => $actor->id]);
            UserProvisioned::dispatch($user->id, $employee?->id, 'administrator', $actor->id);

            return $user;
        });
    }

    /**
     * Candidate conversion: the new employee's login — linked to the employee, the base role only,
     * access Active, and a random password nobody knows; the person sets their own through the
     * invitation link (sent after commit). Needs employees.convert (checked by the conversion),
     * never users.manage.
     */
    public function provisionLogin(Employee $employee, User $actor, string $source): User
    {
        if (blank($employee->email)) {
            throw new DomainException('A login needs an email address. Add the candidate\'s email before converting.');
        }

        if (User::query()->where('email', $employee->email)->exists()) {
            throw new DomainException('Another login already uses this email address. Resolve it under Administration → Users first.');
        }

        $user = User::query()->create([
            'name' => $employee->fullName(),
            'email' => $employee->email,
            'password' => Str::password(40),
            'employee_id' => $employee->id,
        ]);
        $this->joinCurrentTenant($user, $employee);

        $this->roles->grantBaseRole($user, $source, $actor);

        AuditLog::record($user, 'user_provisioned', null, ['employee_id' => $employee->id, 'source' => $source, 'by_user_id' => $actor->id]);
        Log::info('identity.user_provisioned', ['user_id' => $user->id, 'employee_id' => $employee->id, 'source' => $source, 'actor_id' => $actor->id]);
        UserProvisioned::dispatch($user->id, $employee->id, $source, $actor->id);

        $this->sendInvitationAfterCommit($user);

        return $user;
    }

    /**
     * Rehire: the person's existing login comes back — through StaffAccessService, so from Revoked
     * it gets the base role only (earlier roles are never restored blindly) — or a new login is
     * provisioned if there was none.
     */
    public function reactivateForRehire(Employee $employee, User $actor): User
    {
        $user = User::query()->where('employee_id', $employee->id)->first();

        if ($user === null) {
            return $this->provisionLogin($employee, $actor, 'rehire');
        }

        app(StaffAccessService::class)->restore($user, null, 'Rehired', 'rehire');
        $this->joinCurrentTenant($user, $employee);

        if ($user->roles()->doesntExist()) {
            $this->roles->grantBaseRole($user, 'rehire', $actor);
        }

        $this->sendInvitationAfterCommit($user);

        return $user;
    }

    /**
     * A single-use link to set a password (the panel's password-reset page), sent once the
     * surrounding transaction has committed.
     */
    public function sendInvitationAfterCommit(User $user): void
    {
        DB::afterCommit(function () use ($user): void {
            $token = Password::broker()->createToken($user);
            $url = Filament::getPanel('admin')->getResetPasswordUrl($token, $user);

            Mail::to($user->email)->send(new StaffAccessInvitation($user->name, $url, (int) config('auth.passwords.users.expire', 60)));
            AuditLog::record($user, 'invitation_sent', null, null);
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

    /**
     * SaaS-1: a login provisioned (or brought back) by a tenant is an active member of that
     * tenant, linked to its employee there — otherwise it could reach no tenant at all.
     */
    private function joinCurrentTenant(User $user, ?Employee $employee): void
    {
        TenantMembership::query()->updateOrCreate(
            ['tenant_id' => TenantContext::current()->requireId(), 'user_id' => $user->getKey()],
            ['employee_id' => $employee?->getKey(), 'status' => TenantMembershipStatus::Active],
        );
    }
}
