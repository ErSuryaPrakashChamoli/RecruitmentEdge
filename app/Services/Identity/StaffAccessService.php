<?php

namespace App\Services\Identity;

use App\Enums\AccessState;
use App\Events\EmployeeAccessRestored;
use App\Events\EmployeeAccessRevoked;
use App\Events\EmployeeAccessSuspended;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Lifecycle\LifecycleGuard;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use WeakMap;

/**
 * Phase 8.4: the only writer of a staff login's access state (User.access_status is guarded) and
 * the access gate every other layer defers to.
 *
 * - permits() is fail-closed: only an Active login may authenticate or hold any permission
 *   (User::canAccessPanel and User::hasPermissionTo both ask it).
 * - suspend() is a reversible block: roles stay (dormant), sessions end.
 * - revoke() ends authority: roles are removed (recorded in revoked_roles and the audit log),
 *   sessions end. restore() from Revoked grants only the base role — never the old roles.
 * - Every transition is idempotent, audited, locked on the user row, and announced after commit.
 *
 * Callers that act for an administrator pass $actor and are authorised here (AuthorityGuard);
 * the employment lifecycle passes a null actor after authorising its own operation.
 */
class StaffAccessService
{
    /**
     * Bumped on every identity mutation in this process, so a memoised gate decision can never
     * outlive the change that invalidates it.
     */
    private static int $generation = 0;

    /**
     * @var WeakMap<User, array{0: string, 1: bool}>
     */
    private WeakMap $memo;

    public function __construct(
        private readonly SessionRevocationService $sessions,
        private readonly AuthorityGuard $authority,
    ) {
        $this->memo = new WeakMap;
    }

    public static function invalidateDecisions(): void
    {
        self::$generation++;
    }

    public function state(User $user): AccessState
    {
        return $user->access_status ?? AccessState::Active;
    }

    /**
     * Whether $user may authenticate and exercise any permission right now.
     */
    public function permits(User $user): bool
    {
        if ($this->state($user) !== AccessState::Active) {
            return false;
        }

        // A decision holds until an identity change in this process or until the date changes (a
        // separation takes effect at midnight without any write).
        $stamp = self::$generation.'@'.now()->toDateString();
        $cached = $this->memo[$user] ?? null;

        if ($cached !== null && $cached[0] === $stamp) {
            return $cached[1];
        }

        // SaaS-1: employment facts live in the employing tenant, and this runs before a request's
        // tenant is identified (sign-in, EnforceStaffAccess).
        $permitted = ! $user->withinEmployingTenant(fn (): bool => $this->employmentBlocks($user));
        $this->memo[$user] = [$stamp, $permitted];

        return $permitted;
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
            function (User $user): void {
                $roles = $user->roles()->get(['roles.id', 'roles.name', 'roles.key']);
                $user->forceFill(['revoked_roles' => $roles->map(fn (Role $role) => $role->key ?? $role->name)->values()->all()]);
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
            function (User $user, AccessState $from): void {
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
     * @param  (callable(User, AccessState): void)|null  $effects  run after every check passed
     */
    private function transition(User $target, ?User $actor, AccessState $to, string $reason, string $source, callable $decide, ?callable $effects = null): User
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('A reason is required to change access.');
        }

        $user = $this->authority->protecting("access_{$to->value}", $actor, fn (): User => DB::transaction(fn (): User => $this->apply($target, $actor, $reason, $source, $decide, $effects)));

        $target->setRawAttributes($user->getAttributes(), true);
        $target->unsetRelation('roles');

        return $target;
    }

    /**
     * @param  callable(AccessState): ?AccessState  $decide
     * @param  (callable(User, AccessState): void)|null  $effects
     */
    private function apply(User $target, ?User $actor, string $reason, string $source, callable $decide, ?callable $effects): User
    {
        /** @var User $user */
        $user = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();
        $from = $this->state($user);
        $next = $decide($from);

        if ($next === null) {
            return $user;
        }

        if ($next !== AccessState::Active) {
            $this->authority->assertEffectiveChroRemainsWithout($user);
        }

        if ($effects !== null) {
            $effects($user, $from);
        }

        LifecycleGuard::allow(fn () => $user->forceFill([
            'access_status' => $next,
            'access_changed_at' => now(),
            'access_changed_by' => $actor?->getKey(),
            'access_reason' => mb_substr($reason, 0, 255),
            'access_source' => mb_substr($source, 0, 30),
        ])->save());

        AuditLog::record($user, 'access_'.match ($next) {
            AccessState::Active => 'restored',
            AccessState::Suspended => 'suspended',
            AccessState::Revoked => 'revoked',
        }, ['access_status' => $from->value], ['access_status' => $next->value, 'reason' => $reason, 'source' => $source]);

        if ($next !== AccessState::Active) {
            $this->sessions->revokeAll($user, reason: 'access_'.$next->value);
        }

        self::invalidateDecisions();

        Log::info('identity.access_transition', ['user_id' => $user->getKey(), 'from' => $from->value, 'to' => $next->value, 'source' => $source, 'actor_id' => $actor?->getKey()]);

        event(match ($next) {
            AccessState::Active => new EmployeeAccessRestored($user->getKey(), $user->employee_id, $source, $actor?->getKey()),
            AccessState::Suspended => new EmployeeAccessSuspended($user->getKey(), $user->employee_id, $source, $actor?->getKey()),
            AccessState::Revoked => new EmployeeAccessRevoked($user->getKey(), $user->employee_id, $source, $actor?->getKey()),
        });

        return $user;
    }
}
