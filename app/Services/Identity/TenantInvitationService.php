<?php

namespace App\Services\Identity;

use App\Enums\AccessState;
use App\Enums\EmployeeStatus;
use App\Enums\InvitationStatus;
use App\Events\UserProvisioned;
use App\Mail\TenantInvitationMail;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\Lifecycle\LifecycleGuard;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * SaaS-2: the invitation lifecycle — the only way a person becomes a member of a tenant.
 *
 * Inviting never reveals whether the address already has an identity on the platform (S1-01): the
 * outcome is the same pending invitation either way. The person proves they own the address by
 * opening the emailed link, then either signs in as the existing identity with that address or
 * creates the identity (once — the email is unique platform-wide, compared normalised). Nobody
 * else's identity can be attached: acceptance requires the invited address.
 *
 * - Tokens: 64 random characters in the link only; SHA-256 stored; a resend replaces the token.
 * - Every state change is a locked read or a conditional update on the invitation row: two
 *   acceptances, an acceptance and a revocation, or an acceptance and an expiry can never both win.
 *   An identity's acceptances are serialised on its user row; the tenant row is read under a lock,
 *   so a tenant suspended meanwhile is honoured.
 * - Acceptance re-checks what the invitation promised: the roles still exist in the tenant, the
 *   inviter may still grant them, the employee record is still current and unlinked.
 * - Audited in the tenant's stream (created, resent, revoked, accepted, expired, refused) and
 *   logged with ids only — never the token.
 */
class TenantInvitationService
{
    public function __construct(
        private readonly RoleAssignmentService $roles,
        private readonly HierarchyService $hierarchy,
    ) {}

    /**
     * An administrator invites someone into the current tenant with the roles they may grant.
     *
     * @param  array{email: string, name?: ?string, roles?: array<int, int|string>, employee_id?: int|string|null}  $data
     */
    public function invite(array $data, User $actor): TenantInvitation
    {
        if (! $actor->can('users.manage')) {
            throw new DomainException('Inviting people needs the users.manage permission.');
        }

        $email = $this->validEmail((string) $data['email']);
        $roles = $this->rolesToGrant($data['roles'] ?? []);

        if ($roles->isEmpty()) {
            throw new DomainException('Choose at least one role.');
        }

        foreach ($roles as $role) {
            $this->roles->assertCanGrant($actor, $role);
        }

        $employee = filled($data['employee_id'] ?? null) ? $this->linkableEmployee((int) $data['employee_id'], $actor) : null;

        if ($employee === null && $this->hierarchy->visibleEmployeeIdsFor($actor) !== null) {
            throw new DomainException('Someone without an employee record can only be invited by a person with organisation-wide visibility.');
        }

        return $this->create($email, $data['name'] ?? null, $roles, $employee, 'administrator', $actor);
    }

    /**
     * Candidate conversion or rehire: the new employee is invited with the base role only and their
     * employee record. Authorised by the caller (employees.convert), never by users.manage.
     */
    public function inviteEmployee(Employee $employee, User $actor, string $source): TenantInvitation
    {
        if (blank($employee->email)) {
            throw new DomainException('Sign-in access needs an email address. Add the candidate\'s email before converting.');
        }

        $base = Role::byKeyOrFail((string) config('identity.base_role'));

        return $this->create($this->validEmail((string) $employee->email), $employee->fullName(), new Collection([$base]), $employee, $source, $actor);
    }

    /**
     * Sends the invitation again with a new link (the old link stops working) and a new expiry.
     */
    public function resend(TenantInvitation $invitation, User $actor): TenantInvitation
    {
        $this->assertCanAdminister($actor);

        return DB::transaction(function () use ($invitation, $actor): TenantInvitation {
            $locked = $this->lock($invitation);

            if ($locked->status !== InvitationStatus::Pending) {
                throw new DomainException('Only a pending invitation can be sent again.');
            }

            $token = $this->newToken();

            LifecycleGuard::allow(fn () => $locked->forceFill([
                'token_hash' => self::hash($token),
                'expires_at' => $this->expiry(),
                'last_sent_at' => now(),
                'send_count' => $locked->send_count + 1,
            ])->save());

            $this->sendAfterCommit($locked, $token);

            AuditLog::record($locked, 'invitation_resent', null, ['email' => $locked->email, 'send_count' => $locked->send_count, 'by_user_id' => $actor->id]);
            Log::info('identity.invitation_resent', ['invitation_id' => $locked->id, 'actor_id' => $actor->id]);

            return $locked;
        });
    }

    public function revoke(TenantInvitation $invitation, User $actor, string $reason): TenantInvitation
    {
        $this->assertCanAdminister($actor);
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('A reason is required to revoke an invitation.');
        }

        $revoked = TenantInvitation::query()->whereKey($invitation->id)->where('status', InvitationStatus::Pending->value)->update([
            'status' => InvitationStatus::Revoked->value,
            'revoked_at' => now(),
            'revoked_by' => $actor->id,
            'revoked_reason' => mb_substr($reason, 0, 255),
            'updated_at' => now(),
        ]);

        if ($revoked !== 1) {
            throw new DomainException('This invitation is no longer pending.');
        }

        AuditLog::record($invitation, 'invitation_revoked', ['status' => InvitationStatus::Pending->value], ['status' => InvitationStatus::Revoked->value, 'reason' => $reason, 'by_user_id' => $actor->id]);
        Log::info('identity.invitation_revoked', ['invitation_id' => $invitation->id, 'actor_id' => $actor->id]);

        return $invitation->refresh();
    }

    /**
     * Marks the current tenant's pending invitations past their expiry as Expired (scheduled per
     * tenant). Returns how many expired.
     */
    public function expireDue(): int
    {
        $expired = 0;

        TenantInvitation::query()->where('status', InvitationStatus::Pending->value)->where('expires_at', '<=', now())->orderBy('id')->each(function (TenantInvitation $invitation) use (&$expired): void {
            $changed = TenantInvitation::query()->whereKey($invitation->id)->where('status', InvitationStatus::Pending->value)->where('expires_at', '<=', now())
                ->update(['status' => InvitationStatus::Expired->value, 'updated_at' => now()]);

            if ($changed === 1) {
                $expired++;
                AuditLog::record($invitation, 'invitation_expired', ['status' => InvitationStatus::Pending->value], ['status' => InvitationStatus::Expired->value]);
            }
        });

        if ($expired > 0) {
            Log::info('identity.invitations_expired', ['count' => $expired]);
        }

        return $expired;
    }

    /**
     * SaaS-2: the invitation behind a link, whatever its tenant or state — the one platform-level
     * lookup of an invitation (by the token's hash; the token is never stored). The caller then
     * works inside the invitation's own tenant: the tenant comes from the invitation, never from the
     * URL or the request.
     */
    public function findByToken(string $token): ?TenantInvitation
    {
        if (strlen($token) !== 64) {
            return null;
        }

        return TenantInvitation::query()->withoutTenancy()->where('token_hash', self::hash($token))->first();
    }

    /**
     * The invitation for a stored token hash (kept in the session once the link was opened).
     */
    public function findByHash(string $hash): ?TenantInvitation
    {
        return TenantInvitation::query()->withoutTenancy()->where('token_hash', $hash)->first();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Whether the invitation can still be accepted (pending, unexpired, its tenant usable).
     */
    public function isOpen(TenantInvitation $invitation): bool
    {
        return $invitation->isOpen() && (Tenant::query()->find($invitation->tenant_id)?->isUsable() ?? false);
    }

    /**
     * The signed-in identity accepts. Runs inside the invitation's tenant (the caller enters it).
     * Returns the Active membership.
     */
    public function accept(TenantInvitation $invitation, User $user): TenantMembership
    {
        return $this->refusalsAudited($invitation, $user, fn (): TenantMembership => DB::transaction(function () use ($invitation, $user): TenantMembership {
            $locked = $this->lockOpen($invitation);

            if (User::normaliseEmail($user->email) !== User::normaliseEmail($locked->email)) {
                throw new InvitationUnavailable('wrong_identity', 'This invitation was sent to a different email address. Sign out, then open the link again and sign in with that address.');
            }

            // One identity's acceptances run one after the other (two invitations into one tenant).
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            return $this->join($locked, $user);
        }));
    }

    /**
     * Someone without an identity creates it while accepting: the email is the invited address
     * (proven by the link), the password follows the staff policy, and the identity is created once.
     * If the address already has an identity, the person must sign in with it instead.
     */
    public function acceptAsNewIdentity(TenantInvitation $invitation, string $name, string $password): User
    {
        CredentialService::assertAcceptablePassword($password);
        $name = trim($name);

        if ($name === '') {
            throw new DomainException('Enter your name.');
        }

        return $this->refusalsAudited($invitation, null, fn (): User => DB::transaction(function () use ($invitation, $name, $password): User {
            $locked = $this->lockOpen($invitation);

            if (User::query()->whereEmailIs($locked->email)->exists()) {
                throw new InvitationRequiresSignIn;
            }

            try {
                $user = DB::transaction(fn (): User => User::query()->create(['name' => mb_substr($name, 0, 255), 'email' => $locked->email, 'password' => $password]));
            } catch (UniqueConstraintViolationException) {
                // Created a moment ago through another invitation: that identity signs in instead.
                throw new InvitationRequiresSignIn;
            }

            // Opening the emailed link proved the address.
            $user->forceFill(['email_verified_at' => now()])->save();

            AuditLog::record($user, 'identity_created', null, ['source' => 'invitation', 'invitation_id' => $locked->id]);
            Log::info('identity.identity_created', ['user_id' => $user->id, 'invitation_id' => $locked->id]);

            $this->join($locked, $user);

            return $user;
        }));
    }

    /**
     * Runs an acceptance; a refusal is audited (and an invitation found past its expiry is marked
     * Expired) after the acceptance's transaction rolled back, so the record survives.
     *
     * @template T
     *
     * @param  callable(): T  $acceptance
     * @return T
     */
    private function refusalsAudited(TenantInvitation $invitation, ?User $user, callable $acceptance): mixed
    {
        try {
            return $acceptance();
        } catch (InvitationUnavailable $refused) {
            if ($refused->reason === 'expired') {
                $expired = TenantInvitation::query()->whereKey($invitation->id)->where('status', InvitationStatus::Pending->value)->update(['status' => InvitationStatus::Expired->value, 'updated_at' => now()]);

                if ($expired === 1) {
                    AuditLog::record($invitation, 'invitation_expired', ['status' => InvitationStatus::Pending->value], ['status' => InvitationStatus::Expired->value]);
                }
            } elseif ($refused->reason !== 'not_pending') {
                AuditLog::record($invitation, 'invitation_refused', null, ['reason' => $refused->reason, 'user_id' => $user?->getKey()]);
            }

            Log::notice('identity.invitation_refused', ['invitation_id' => $invitation->id, 'reason' => $refused->reason, 'user_id' => $user?->getKey()]);

            throw $refused;
        }
    }

    /**
     * Creates (or re-activates a revoked) membership for $user from the locked, open invitation.
     */
    private function join(TenantInvitation $invitation, User $user): TenantMembership
    {
        /** @var TenantMembership|null $membership */
        $membership = TenantMembership::query()->where('tenant_id', $invitation->tenant_id)->where('user_id', $user->getKey())->lockForUpdate()->first();

        if ($membership?->status === AccessState::Suspended) {
            throw new InvitationUnavailable('membership_suspended', 'Your access to this organisation is suspended. Contact its administrator.');
        }

        if ($membership?->status === AccessState::Active) {
            // Already a member (another invitation won): this one is used up, nothing else changes —
            // a stale invitation never adds roles to an existing membership.
            $this->markAccepted($invitation, $user);
            AuditLog::record($invitation, 'invitation_accepted', null, ['user_id' => $user->getKey(), 'already_member' => true]);

            return $membership;
        }

        $roles = $this->stillGrantable($invitation, $user);
        $employee = $this->stillLinkable($invitation, $user);

        if ($membership === null) {
            $membership = TenantMembership::query()->create([
                'tenant_id' => $invitation->tenant_id,
                'user_id' => $user->getKey(),
                'employee_id' => $employee?->getKey(),
                'status' => AccessState::Active,
                'joined_at' => now(),
            ]);
            $event = 'membership_created';
        } else {
            LifecycleGuard::allow(fn () => $membership->forceFill([
                'status' => AccessState::Active,
                'employee_id' => $employee?->getKey() ?? $membership->employee_id,
                'status_changed_at' => now(),
                'status_changed_by' => $invitation->invited_by,
                'status_reason' => 'Invitation accepted',
                'status_source' => 'invitation',
            ])->save());
            $event = 'membership_activated';
        }

        $this->markAccepted($invitation, $user);
        StaffAccessService::invalidateDecisions();
        $user->unsetRelation('memberships');

        $this->roles->grantInvitedRoles($user, $roles, $invitation);

        AuditLog::record($invitation, 'invitation_accepted', null, ['user_id' => $user->getKey(), 'membership_id' => $membership->getKey()]);
        AuditLog::record($user, $event, null, ['membership_id' => $membership->getKey(), 'invitation_id' => $invitation->getKey(), 'employee_id' => $employee?->getKey(), 'roles' => $roles->pluck('name')->sort()->values()->all()]);
        Log::info('identity.'.$event, ['user_id' => $user->getKey(), 'membership_id' => $membership->getKey(), 'invitation_id' => $invitation->getKey()]);
        UserProvisioned::dispatch((int) $user->getKey(), $employee?->getKey(), $invitation->source, $invitation->invited_by);

        return $membership;
    }

    private function markAccepted(TenantInvitation $invitation, User $user): void
    {
        $accepted = TenantInvitation::query()->whereKey($invitation->id)->where('status', InvitationStatus::Pending->value)->update([
            'status' => InvitationStatus::Accepted->value,
            'accepted_at' => now(),
            'accepted_user_id' => $user->getKey(),
            'updated_at' => now(),
        ]);

        if ($accepted !== 1) {
            throw new InvitationUnavailable('not_pending');
        }
    }

    /**
     * @param  Collection<int, Role>  $roles
     */
    private function create(string $email, ?string $name, Collection $roles, ?Employee $employee, string $source, User $actor): TenantInvitation
    {
        $existing = User::query()->whereEmailIs($email)->first();
        $membership = $existing?->currentMembership();

        // A member of THIS tenant — visible to its administrators anyway — is not invited again; a
        // revoked membership may be (acceptance re-activates it). Nothing is said about any other
        // tenant or about the platform.
        if ($membership !== null && $membership->status !== AccessState::Revoked) {
            throw new DomainException('A member of this organisation already uses this email address.');
        }

        return DB::transaction(function () use ($email, $name, $roles, $employee, $source, $actor): TenantInvitation {
            // One open invitation per address and tenant: an earlier one is superseded.
            TenantInvitation::query()->where('email', $email)->where('status', InvitationStatus::Pending->value)->update([
                'status' => InvitationStatus::Revoked->value,
                'revoked_at' => now(),
                'revoked_by' => $actor->id,
                'revoked_reason' => 'Superseded by a new invitation',
                'updated_at' => now(),
            ]);

            $token = $this->newToken();

            $invitation = TenantInvitation::query()->create([
                'email' => $email,
                'name' => filled($name) ? mb_substr(trim((string) $name), 0, 255) : null,
                'role_ids' => $roles->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all(),
                'employee_id' => $employee?->getKey(),
                'source' => $source,
                'token_hash' => self::hash($token),
                'status' => InvitationStatus::Pending,
                'expires_at' => $this->expiry(),
                'invited_by' => $actor->id,
                'last_sent_at' => now(),
                'send_count' => 1,
            ]);

            $this->sendAfterCommit($invitation, $token);

            AuditLog::record($invitation, 'invitation_created', null, ['email' => $email, 'roles' => $roles->pluck('name')->sort()->values()->all(), 'employee_id' => $employee?->getKey(), 'source' => $source, 'by_user_id' => $actor->id]);
            Log::info('identity.invitation_created', ['invitation_id' => $invitation->id, 'source' => $source, 'actor_id' => $actor->id]);

            return $invitation;
        });
    }

    /**
     * The tenant's mail carries the tenant's own name and a link that names no tenant: the tenant
     * is the invitation's, found from the token.
     */
    private function sendAfterCommit(TenantInvitation $invitation, string $token): void
    {
        $tenantName = TenantContext::current()->requireTenant()->name;
        $inviter = $invitation->invited_by !== null ? User::query()->find($invitation->invited_by)?->name : null;
        $url = route('invitations.open', ['token' => $token]);

        DB::afterCommit(fn () => Mail::to($invitation->email)->send(new TenantInvitationMail($tenantName, $inviter, $url, $invitation->expires_at->toDayDateTimeString())));
    }

    /**
     * Locks the invitation row with a fresh read and refuses unless it can still be accepted. An
     * invitation past its expiry is marked Expired on the spot.
     */
    private function lockOpen(TenantInvitation $invitation): TenantInvitation
    {
        $locked = $this->lock($invitation);

        if ($locked->status !== InvitationStatus::Pending) {
            throw new InvitationUnavailable('not_pending');
        }

        if (! $locked->expires_at->isFuture()) {
            throw new InvitationUnavailable('expired');
        }

        // The tenant's state under lock: suspended or cancelled meanwhile means no new membership.
        $tenant = Tenant::query()->whereKey($locked->tenant_id)->sharedLock()->first();

        if ($tenant === null || ! $tenant->isUsable()) {
            throw new InvitationUnavailable('tenant_unusable');
        }

        return $locked;
    }

    private function lock(TenantInvitation $invitation): TenantInvitation
    {
        /** @var TenantInvitation $locked */
        $locked = TenantInvitation::query()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();

        return $locked;
    }

    /**
     * The invitation's roles, as they are now: all must still exist in the tenant, and the inviter
     * must still hold the authority to grant them (conversion and rehire invite with the base role
     * only, which their own authorisation covered).
     *
     * @return Collection<int, Role>
     */
    private function stillGrantable(TenantInvitation $invitation, User $user): Collection
    {
        $ids = array_map('intval', (array) $invitation->role_ids);
        $roles = Role::query()->forCurrentTenant()->with('permissions')->whereKey($ids)->get();

        if ($roles->count() !== count(array_unique($ids)) || $roles->isEmpty()) {
            throw new InvitationUnavailable('roles_changed');
        }

        if ($invitation->source !== 'administrator') {
            if ($roles->pluck('key')->all() !== [(string) config('identity.base_role')]) {
                throw new InvitationUnavailable('roles_changed');
            }

            return $roles;
        }

        $inviter = $invitation->invited_by !== null ? User::query()->find($invitation->invited_by) : null;

        if ($inviter === null || ! $inviter->can('users.manage') || $roles->contains(fn (Role $role): bool => ! $this->roles->canGrant($inviter, $role))) {
            throw new InvitationUnavailable('inviter_authority_changed');
        }

        return $roles;
    }

    private function stillLinkable(TenantInvitation $invitation, User $user): ?Employee
    {
        if ($invitation->employee_id === null) {
            return null;
        }

        $employee = Employee::query()->find($invitation->employee_id);
        $linked = $employee !== null && User::query()->linkedToEmployee($employee->id)->whereKeyNot($user->getKey())->exists();

        if ($employee === null || $employee->status !== EmployeeStatus::Active || $linked) {
            throw new InvitationUnavailable('employee_unavailable');
        }

        return $employee;
    }

    /**
     * An employee an invitation may link: current, in the inviter's hierarchy, without a login.
     */
    private function linkableEmployee(int $employeeId, User $actor): Employee
    {
        $employee = Employee::query()->find($employeeId);

        if ($employee === null || $employee->status !== EmployeeStatus::Active) {
            throw new DomainException('An invitation can only be linked to a current (active) employee.');
        }

        if (! $this->hierarchy->canView($actor, $employee)) {
            throw new DomainException('This employee is outside your hierarchy.');
        }

        if (User::query()->linkedToEmployee($employee->id)->exists()) {
            throw new DomainException('This employee already has a login.');
        }

        return $employee;
    }

    /**
     * @param  array<int, int|string>  $roleIds
     * @return Collection<int, Role>
     */
    private function rolesToGrant(array $roleIds): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $roleIds)));
        $roles = Role::query()->forCurrentTenant()->with('permissions')->whereKey($ids)->get();

        // SaaS-1: another tenant's role id never resolves here.
        if ($roles->count() !== count($ids)) {
            throw new DomainException('One of the chosen roles does not exist in this organisation.');
        }

        return $roles;
    }

    private function assertCanAdminister(User $actor): void
    {
        if (! $actor->can('users.manage')) {
            throw new DomainException('Managing invitations needs the users.manage permission.');
        }
    }

    private function validEmail(string $email): string
    {
        $email = User::normaliseEmail($email);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 255) {
            throw new DomainException('Enter a valid email address.');
        }

        return $email;
    }

    private function newToken(): string
    {
        return Str::random(64);
    }

    private function expiry(): Carbon
    {
        return now()->addHours((int) config('identity.invitations.ttl_hours', 72));
    }
}
