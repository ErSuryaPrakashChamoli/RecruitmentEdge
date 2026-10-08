<?php

namespace App\Services\Identity;

use App\Enums\AccessState;
use App\Enums\EmployeeStatus;
use App\Mail\StaffAccessInvitation;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\TenantInvitation;
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

/**
 * Phase 8.4: the only way a staff login is linked to an employee record (the link decides whose
 * team a login sees). A login is linked only to a current (active, not deleted) employee inside the
 * administrator's hierarchy who has no other login, and never by the person to themselves. Roles
 * always go through RoleAssignmentService.
 *
 * SaaS-2: the link is the tenant membership's (TenantMembership.employee_id, guarded). New logins
 * are never created here: a person joins a tenant by accepting an invitation
 * (TenantInvitationService), which creates the identity only if the address has none.
 */
class IdentityProvisioningService
{
    public function __construct(
        private readonly RoleAssignmentService $roles,
        private readonly AuthorityGuard $authority,
        private readonly HierarchyService $hierarchy,
    ) {}

    /**
     * SaaS-2: candidate conversion invites the new employee — the base role only, linked to their
     * employee record. The login exists once the person accepts: by signing in with the identity
     * that already uses the address, or by creating it. The outcome (and everything an
     * administrator sees) is the same either way, so a conversion never reveals whether the address
     * is known on the platform (S1-01). Needs employees.convert (checked by the conversion), never
     * users.manage.
     */
    public function inviteNewEmployee(Employee $employee, User $actor, string $source): TenantInvitation
    {
        return app(TenantInvitationService::class)->inviteEmployee($employee, $actor, $source);
    }

    /**
     * Rehire: the person's existing login in this tenant comes back — through StaffAccessService,
     * so from Revoked it gets the base role only (earlier roles are never restored blindly) — or the
     * person is invited if this employee record never had a login.
     */
    public function reactivateForRehire(Employee $employee, User $actor): User|TenantInvitation
    {
        $user = User::query()->linkedToEmployee($employee->id)->first();

        if ($user === null) {
            return $this->inviteNewEmployee($employee, $actor, 'rehire');
        }

        app(StaffAccessService::class)->restore($user, null, 'Rehired', 'rehire');
        $this->joinCurrentTenant($user, $employee);

        if ($user->roles()->doesntExist()) {
            $this->roles->grantBaseRole($user, 'rehire', $actor);
        }

        // A password link only for an identity this tenant alone holds; a person who belongs to
        // another organisation keeps the password they already use.
        if ($user->belongsOnlyToCurrentTenant()) {
            $this->sendInvitationAfterCommit($user);
        }

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
            $membership = $user->currentMembership() ?? throw new DomainException('This person is not a member of this organisation.');

            // SaaS-2: the employee link belongs to this tenant's membership.
            LifecycleGuard::allow(fn () => $membership->forceFill(['employee_id' => $employee?->id])->save());
            $user->unsetRelation('employee');

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

        if (User::query()->linkedToEmployee($employee->id)->when($for !== null, fn ($query) => $query->whereKeyNot($for->id))->exists()) {
            throw new DomainException('This employee already has a login.');
        }

        return $employee;
    }

    /**
     * SaaS-1/2: a login provisioned (or brought back) by a tenant is a member of that tenant,
     * linked to its employee there — otherwise it could reach no tenant at all. An existing
     * membership keeps its access state (StaffAccessService owns it); only the link is set.
     */
    private function joinCurrentTenant(User $user, ?Employee $employee): void
    {
        $tenantId = TenantContext::current()->requireId();
        $membership = TenantMembership::query()->where('tenant_id', $tenantId)->where('user_id', $user->getKey())->lockForUpdate()->first();

        if ($membership === null) {
            TenantMembership::query()->create(['tenant_id' => $tenantId, 'user_id' => $user->getKey(), 'employee_id' => $employee?->getKey(), 'status' => AccessState::Active, 'joined_at' => now()]);
        } elseif ($employee !== null && (int) $membership->employee_id !== (int) $employee->getKey()) {
            LifecycleGuard::allow(fn () => $membership->forceFill(['employee_id' => $employee->getKey()])->save());
        }

        StaffAccessService::invalidateDecisions();
        $user->unsetRelation('memberships');
    }
}
