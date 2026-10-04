<?php

namespace App\Services\Identity;

use App\Enums\AccessState;
use App\Enums\TenantStatus;
use App\Events\EmployeeAccessRestored;
use App\Events\EmployeeAccessRevoked;
use App\Events\EmployeeAccessSuspended;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Lifecycle\LifecycleGuard;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use WeakMap;

/**
 * Phase 8.4 / SaaS-2: the only writer of a staff membership's access state
 * (TenantMembership.status is guarded) and the access gate every other layer defers to.
 *
 * Access belongs to the membership — one person, one state per tenant:
 * - permits() answers for the CURRENT tenant and fails closed: no tenant, no membership there, a
 *   membership that is not Active, a tenant that is not usable, an identity the platform disabled,
 *   or an employment fact in that tenant (a due separation) — no permission at all
 *   (User::hasPermissionTo, Copilot, automation ownership, jobs all ask it).
 * - accessibleTenants() is the set of tenants the identity may enter (panel sign-in, the tenant
 *   switcher, Filament's canAccessTenant on every request).
 * - suspend() is a reversible block of the membership: the tenant's roles stay (dormant).
 * - revoke() ends authority in the tenant: its roles are removed (recorded in revoked_roles and the
 *   audit log). restore() from Revoked grants only the base role — never the old roles.
 * - A transition changes this tenant's membership only. The person's sessions end when no tenant
 *   is left to them; otherwise every request re-checks the membership, so the suspended tenant is
 *   closed at once while their other tenants keep working.
 * - Every transition is idempotent, audited in the tenant's stream, locked on the membership row,
 *   and announced after commit.
 *
 * Callers that act for an administrator pass $actor and are authorised here (AuthorityGuard);
 * the employment lifecycle passes a null actor after authorising its own operation.
 */
class StaffAccessService
{
    /**
     * Bumped on every identity mutation in this process, so a memoised gate decision (or a loaded
     * set of memberships) can never outlive the change that invalidates it.
     */
    private static int $generation = 0;

    /**
     * @var WeakMap<User, array<int, array{0: string, 1: bool}>> per tenant id
     */
    private WeakMap $memo;

    /**
     * @var WeakMap<User, array{0: string, 1: Collection<int, Tenant>}>
     */
    private WeakMap $tenantsMemo;

    public function __construct(
        private readonly SessionRevocationService $sessions,
        private readonly AuthorityGuard $authority,
    ) {
        $this->memo = new WeakMap;
        $this->tenantsMemo = new WeakMap;
    }

    public static function invalidateDecisions(): void
    {
        self::$generation++;
    }

    public static function generation(): int
    {
        return self::$generation;
    }

    /**
     * The access state of $user's membership in the current tenant; null when there is none.
     */
    public function state(User $user): ?AccessState
    {
        return $user->currentMembership()?->status;
    }

    /**
     * SaaS-2: the identity itself is usable — not disabled by the platform. Says nothing about any
     * tenant.
     */
    public function identityPermits(User $user): bool
    {
        return $user->disabled_at === null;
    }

    /**
     * Whether $user may act in the current tenant right now.
     */
    public function permits(User $user): bool
    {
        $tenantId = TenantContext::current()->id();

        if ($tenantId === null || ! $this->identityPermits($user) || $this->state($user) !== AccessState::Active) {
            return false;
        }

        if (! (TenantContext::current()->tenant()?->isUsable() ?? false)) {
            return false;
        }

        // A decision holds until an identity change in this process or until the date changes (a
        // separation takes effect at midnight without any write).
        $stamp = $this->stamp();
        $cached = $this->memo[$user][$tenantId] ?? null;

        if ($cached !== null && $cached[0] === $stamp) {
            return $cached[1];
        }

        $permitted = ! $this->employmentBlocks($user);
        $decisions = $this->memo[$user] ?? [];
        $decisions[$tenantId] = [$stamp, $permitted];
        $this->memo[$user] = $decisions;

        return $permitted;
    }

    /**
     * Whether $user may act in $tenant — decided inside that tenant (its membership, its employment
     * facts), whatever tenant the caller is in. The caller's context is restored afterwards.
     */
    public function permitsIn(User $user, Tenant|int $tenant): bool
    {
        $tenantId = $tenant instanceof Tenant ? (int) $tenant->getKey() : $tenant;

        return $tenantId === TenantContext::current()->id()
            ? $this->permits($user)
            : TenantContext::current()->run($tenant, fn (): bool => $this->permits($user));
    }

    /**
     * SaaS-2: the tenants $user may enter, by name: an Active membership, a usable tenant, at least
     * one role there, and permitted there (no employment block). Read with one query, then each
     * candidate is confirmed inside its own tenant; memoised like permits().
     *
     * @return Collection<int, Tenant>
     */
    public function accessibleTenants(User $user): Collection
    {
        if (! $user->exists || ! $this->identityPermits($user)) {
            return collect();
        }

        $stamp = $this->stamp();
        $cached = $this->tenantsMemo[$user] ?? null;

        if ($cached !== null && $cached[0] === $stamp) {
            return $cached[1];
        }

        $tenantIds = DB::table('tenant_memberships')
            ->join('tenants', 'tenants.id', '=', 'tenant_memberships.tenant_id')
            ->where('tenant_memberships.user_id', $user->getKey())
            ->where('tenant_memberships.status', AccessState::Active->value)
            ->whereIn('tenants.status', TenantStatus::usableValues())
            ->whereExists(fn ($query) => $query->from('model_has_roles')
                ->where('model_has_roles.model_type', $user->getMorphClass())
                ->where('model_has_roles.model_id', $user->getKey())
                ->whereColumn('model_has_roles.tenant_id', 'tenant_memberships.tenant_id'))
            ->pluck('tenant_memberships.tenant_id');

        $tenants = $tenantIds->isEmpty() ? collect() : Tenant::query()
            ->whereKey($tenantIds->all())
            ->orderBy('name')
            ->get()
            ->filter(fn (Tenant $tenant): bool => $this->permitsIn($user, $tenant))
            ->values();

        $this->tenantsMemo[$user] = [$stamp, $tenants];

        return $tenants;
    }

    /**
     * Employment facts that end access even before the access state has been updated — the
     * gate fails closed. Extended by the employment lifecycle (effective separations).
     */
    protected function employmentBlocks(User $user): bool
    {
        return app(EmploymentGate::class)->blocks($user);
    }

    public function suspend(User $target, ?User $actor, string $reason, string $source = 'administrator'): User
    {
        if ($actor !== null) {
            $this->authority->assertCanManageAccess($actor, $target);
        }

        return $this->transition($target, $actor, AccessState::Suspended, $reason, $source, fn (AccessState $from): ?AccessState => match ($from) {
            AccessState::Suspended => null,
            AccessState::Revoked => throw new DomainException('This login is revoked; it cannot be suspended.'),
            AccessState::Active => AccessState::Suspended,
        });
    }

    public function revoke(User $target, ?User $actor, string $reason, string $source = 'administrator'): User
    {
        if ($actor !== null) {
            $this->authority->assertCanManageAccess($actor, $target);
        }

        return $this->transition(
            $target,
            $actor,
            AccessState::Revoked,
            $reason,
            $source,
            fn (AccessState $from): ?AccessState => $from === AccessState::Revoked ? null : AccessState::Revoked,
            function (User $user, TenantMembership $membership): void {
                // SaaS-1/2: role assignments are keyed by tenant (spatie teams); this reads and
                // removes the current tenant's roles only.
                $roles = $user->roles()->get(['roles.id', 'roles.name', 'roles.key']);
                $membership->forceFill(['revoked_roles' => $roles->map(fn (Role $role) => $role->key ?? $role->name)->values()->all()]);
                $user->roles()->detach();

                AuditLog::record($user, 'roles_removed', ['roles' => $roles->pluck('name')->values()->all()], ['roles' => [], 'reason' => 'access_revoked']);
            },
        );
    }

    /**
     * Back to Active. From Suspended the dormant roles apply again; from Revoked only the base role
     * is granted — earlier (possibly privileged) roles are never restored automatically.
     */
    public function restore(User $target, ?User $actor, string $reason, string $source = 'administrator'): User
    {
        if ($actor !== null) {
            $this->authority->assertCanManageAccess($actor, $target);
        }

        if (! app(EmploymentGate::class)->isEmployed($target)) {
            throw new DomainException('This person is not currently employed (inactive or separated), so their access cannot be restored.');
        }

        return $this->transition(
            $target,
            $actor,
            AccessState::Active,
            $reason,
            $source,
            fn (AccessState $from): ?AccessState => $from === AccessState::Active ? null : AccessState::Active,
            function (User $user, TenantMembership $membership, AccessState $from): void {
                if ($from !== AccessState::Revoked) {
                    return;
                }

                $base = Role::byKeyOrFail((string) config('identity.base_role'));
                // SaaS-1: role assignments are keyed by tenant (spatie teams); sync() only sees and
                // replaces this tenant's assignments, and the new one is stamped with it.
                $user->roles()->sync([$base->getKey() => ['tenant_id' => TenantContext::current()->requireId()]]);
                AuditLog::record($user, 'roles_assigned', ['roles' => []], ['roles' => [$base->name], 'reason' => 'access_restored_base_role']);
            },
        );
    }

    /**
     * @param  callable(AccessState): ?AccessState  $decide  the target state, or null for a no-op
     * @param  (callable(User, TenantMembership, AccessState): void)|null  $effects  run after every check passed
     */
    private function transition(User $target, ?User $actor, AccessState $to, string $reason, string $source, callable $decide, ?callable $effects = null): User
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('A reason is required to change access.');
        }

        $changed = $this->authority->protecting("access_{$to->value}", $actor, fn (): ?AccessState => DB::transaction(fn (): ?AccessState => $this->apply($target, $actor, $reason, $source, $decide, $effects)));

        $target->unsetRelation('roles');
        $target->unsetRelation('memberships');

        // The identity's sessions end only when no tenant is left to it; a person who still belongs
        // to another tenant keeps working there (every request re-checks this tenant's membership).
        if ($changed !== null && $changed !== AccessState::Active && $this->accessibleTenants($target)->isEmpty()) {
            $this->sessions->revokeAll($target, reason: 'access_'.$changed->value);
        }

        return $target;
    }

    /**
     * @param  callable(AccessState): ?AccessState  $decide
     * @param  (callable(User, TenantMembership, AccessState): void)|null  $effects
     * @return AccessState|null the new state, or null when nothing changed
     */
    private function apply(User $target, ?User $actor, string $reason, string $source, callable $decide, ?callable $effects): ?AccessState
    {
        /** @var TenantMembership|null $membership */
        $membership = TenantMembership::query()
            ->where('tenant_id', TenantContext::current()->requireId())
            ->where('user_id', $target->getKey())
            ->lockForUpdate()
            ->first();

        if ($membership === null) {
            throw new DomainException('This person is not a member of this organisation.');
        }

        $from = $membership->status;
        $next = $decide($from);

        if ($next === null) {
            return null;
        }

        if ($next !== AccessState::Active) {
            $this->authority->assertEffectiveChroRemainsWithout($target);
        }

        if ($effects !== null) {
            $effects($target, $membership, $from);
        }

        LifecycleGuard::allow(fn () => $membership->forceFill([
            'status' => $next,
            'status_changed_at' => now(),
            'status_changed_by' => $actor?->getKey(),
            'status_reason' => mb_substr($reason, 0, 255),
            'status_source' => mb_substr($source, 0, 30),
        ])->save());

        self::invalidateDecisions();

        AuditLog::record($target, 'access_'.match ($next) {
            AccessState::Active => 'restored',
            AccessState::Suspended => 'suspended',
            AccessState::Revoked => 'revoked',
        }, ['access_status' => $from->value], ['access_status' => $next->value, 'reason' => $reason, 'source' => $source]);

        Log::info('identity.access_transition', ['user_id' => $target->getKey(), 'membership_id' => $membership->getKey(), 'from' => $from->value, 'to' => $next->value, 'source' => $source, 'actor_id' => $actor?->getKey()]);

        $employeeId = $membership->employee_id === null ? null : (int) $membership->employee_id;

        event(match ($next) {
            AccessState::Active => new EmployeeAccessRestored($target->getKey(), $employeeId, $source, $actor?->getKey()),
            AccessState::Suspended => new EmployeeAccessSuspended($target->getKey(), $employeeId, $source, $actor?->getKey()),
            AccessState::Revoked => new EmployeeAccessRevoked($target->getKey(), $employeeId, $source, $actor?->getKey()),
        });

        return $next;
    }

    private function stamp(): string
    {
        return self::$generation.'@'.now()->toDateString();
    }
}
