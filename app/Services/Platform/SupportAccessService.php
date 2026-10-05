<?php

namespace App\Services\Platform;

use App\Enums\AccessState;
use App\Enums\PlatformCapability;
use App\Enums\PlatformEventSeverity;
use App\Enums\PlatformRole;
use App\Enums\SupportGrantStatus;
use App\Enums\SupportScope;
use App\Enums\TenantStatus;
use App\Models\AuditLog;
use App\Models\PlatformOperator;
use App\Models\SupportAccessGrant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\NotificationDispatchService;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SaaS-2 foundation, SaaS-5 operational: a tenant's explicit, scoped, time-bound, revocable permission
 * for one platform support operator to look into this tenant — never a membership, a role, an
 * ownership or billing change, or sign-in-as.
 *
 * - A support operator (platform.support.manage) requests access: scopes, minutes, reason. The
 *   tenant's access administrators (users.access.manage) are told and approve — a subset of the
 *   scopes, at most the minutes asked and platform.support.max_minutes — or deny. A tenant may also
 *   grant access directly.
 * - While active, the grant opens the operator's read-only support workspace for the granted scopes
 *   only (open(): checked under the grant's row lock — the operator, the tenant, the status, the
 *   time window, the scope — and audited every time). Expired, revoked or denied grants open nothing.
 * - Revocable at any time by the tenant, by the operator, or by a platform administrator.
 *
 * Lock order: tenant row, then the grant row (approval, revocation); a use or an expiry locks the
 * grant row only. Every step is audited in the tenant's own stream and the platform's event feed.
 */
class SupportAccessService
{
    public function __construct(
        private readonly PlatformAuthorization $authorization,
        private readonly PlatformEvents $events,
    ) {}

    /**
     * A support operator asks a tenant for access.
     *
     * @param  list<SupportScope>  $scopes
     */
    public function request(Tenant $tenant, array $scopes, int $minutes, string $reason, User $operatorUser): SupportAccessGrant
    {
        $this->authorization->authorize($operatorUser, PlatformCapability::SupportManage);
        $operator = $this->supportOperator($operatorUser);
        $reason = $this->reason($reason);
        $scopes = $this->scopes($scopes);
        $this->minutes($minutes);

        if (! $this->openForSupport($tenant)) {
            throw new DomainException("{$tenant->status->label()} tenants cannot be given support access.");
        }

        $grant = TenantContext::current()->run($tenant, function () use ($tenant, $operator, $operatorUser, $scopes, $minutes, $reason): SupportAccessGrant {
            return DB::transaction(function () use ($tenant, $operator, $operatorUser, $scopes, $minutes, $reason): SupportAccessGrant {
                Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->first();

                /** @var SupportAccessGrant|null $open */
                $open = SupportAccessGrant::query()->where('platform_operator_id', $operator->getKey())->whereIn('status', [SupportGrantStatus::Requested->value, SupportGrantStatus::Active->value])->lockForUpdate()->latest('id')->first();

                if ($open !== null && ($open->status === SupportGrantStatus::Requested || $open->isActive())) {
                    return $open;
                }

                $grant = SupportAccessGrant::query()->create([
                    'platform_operator_id' => $operator->getKey(),
                    'status' => SupportGrantStatus::Requested,
                    'reason' => $reason,
                    'requested_scopes' => array_map(fn (SupportScope $scope): string => $scope->value, $scopes),
                    'requested_minutes' => $minutes,
                    'requested_at' => now(),
                ]);

                AuditLog::asPlatformOperator($operatorUser, fn () => AuditLog::record($grant, 'support_access_requested', null, ['platform_operator_id' => $operator->getKey(), 'scopes' => $grant->requested_scopes, 'minutes' => $minutes], $reason));

                return $grant;
            });
        });

        if ($grant->wasRecentlyCreated) {
            $this->notifyTenantAdministrators($tenant, $grant, $operatorUser);
            $this->events->record('support.requested', PlatformEventSeverity::Info, "Support access requested for {$tenant->slug}", $tenant, ['grant_id' => $grant->id, 'operator_user_id' => $operatorUser->id], "support.requested:{$grant->id}");
        }

        return $grant;
    }

    /**
     * The tenant's access administrators approve a request — the scopes and minutes they choose
     * (within what was asked).
     *
     * @param  list<SupportScope>  $scopes
     */
    public function approve(SupportAccessGrant $grant, array $scopes, int $minutes, User $actor): SupportAccessGrant
    {
        $this->assertTenantAdministrator($grant, $actor);
        $scopes = $this->scopes($scopes);
        $this->minutes($minutes);

        $grant = DB::transaction(function () use ($grant, $scopes, $minutes, $actor): SupportAccessGrant {
            $tenant = Tenant::query()->whereKey($grant->tenant_id)->lockForUpdate()->firstOrFail();
            $locked = $this->lockGrant($grant);

            if (! $tenant->isUsable()) {
                throw new DomainException('Support access cannot be approved while the organisation is not active.');
            }

            if ($locked->status !== SupportGrantStatus::Requested) {
                throw new DomainException("This support request is {$locked->status->value}.");
            }

            if ($minutes > (int) $locked->requested_minutes) {
                throw new DomainException("At most the {$locked->requested_minutes} minutes that were asked for can be approved.");
            }

            $values = array_map(fn (SupportScope $scope): string => $scope->value, $scopes);

            if (array_diff($values, (array) $locked->requested_scopes) !== []) {
                throw new DomainException('Only the scopes that were asked for can be approved.');
            }

            $locked->forceFill(['status' => SupportGrantStatus::Active, 'scopes' => $values, 'granted_by' => $actor->getKey(), 'decided_at' => now(), 'starts_at' => now(), 'expires_at' => now()->addMinutes($minutes)])->save();
            AuditLog::record($locked, 'support_access_approved', ['status' => SupportGrantStatus::Requested->value], ['status' => SupportGrantStatus::Active->value, 'scopes' => $values, 'minutes' => $minutes, 'expires_at' => $locked->expires_at->toIso8601String(), 'by_user_id' => $actor->getKey()]);

            return $locked;
        });

        $this->events->record('support.approved', PlatformEventSeverity::Info, 'Support access approved', $grant->tenant()->first(), ['grant_id' => $grant->id], "support.approved:{$grant->id}");

        return $grant;
    }

    public function deny(SupportAccessGrant $grant, string $reason, User $actor): SupportAccessGrant
    {
        $this->assertTenantAdministrator($grant, $actor);
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($grant, $reason, $actor): SupportAccessGrant {
            Tenant::query()->whereKey($grant->tenant_id)->lockForUpdate()->first();
            $locked = $this->lockGrant($grant);

            if ($locked->status !== SupportGrantStatus::Requested) {
                throw new DomainException("This support request is {$locked->status->value}.");
            }

            $locked->forceFill(['status' => SupportGrantStatus::Denied, 'decided_at' => now(), 'granted_by' => $actor->getKey(), 'denial_reason' => $reason])->save();
            AuditLog::record($locked, 'support_access_denied', ['status' => SupportGrantStatus::Requested->value], ['status' => SupportGrantStatus::Denied->value, 'by_user_id' => $actor->getKey()], $reason);

            return $locked;
        });
    }

    /**
     * A tenant grants access directly (SaaS-2), now scoped (least privileged by default).
     *
     * @param  list<SupportScope>  $scopes
     */
    public function grant(PlatformOperator $operator, int $minutes, string $reason, User $actor, array $scopes = [SupportScope::Diagnostics]): SupportAccessGrant
    {
        if (! $actor->can('users.access.manage')) {
            throw new DomainException('Granting support access needs the users.access.manage permission.');
        }

        if (! $operator->isActive() || $operator->role !== PlatformRole::Support) {
            throw new DomainException('Support access can only be granted to an active platform support operator.');
        }

        $this->minutes($minutes);
        $reason = $this->reason($reason);
        $values = array_map(fn (SupportScope $scope): string => $scope->value, $this->scopes($scopes));

        $grant = SupportAccessGrant::query()->create([
            'platform_operator_id' => $operator->getKey(),
            'status' => SupportGrantStatus::Active,
            'granted_by' => $actor->getKey(),
            'reason' => $reason,
            'requested_scopes' => $values,
            'scopes' => $values,
            'requested_minutes' => $minutes,
            'requested_at' => now(),
            'decided_at' => now(),
            'starts_at' => now(),
            'expires_at' => now()->addMinutes($minutes),
        ]);

        AuditLog::record($grant, 'support_access_granted', null, ['platform_operator_id' => $operator->getKey(), 'minutes' => $minutes, 'scopes' => $values, 'reason' => $grant->reason, 'by_user_id' => $actor->getKey()]);
        Log::notice('identity.support_access_granted', ['grant_id' => $grant->getKey(), 'platform_operator_id' => $operator->getKey(), 'actor_id' => $actor->getKey()]);

        return $grant;
    }

    /**
     * Revocation by the tenant's access administrators (SaaS-2 path).
     */
    public function revoke(SupportAccessGrant $grant, User $actor, string $reason): void
    {
        $this->assertTenantAdministrator($grant, $actor);
        $this->revokeLocked($grant, trim($reason), $actor, 'tenant');
    }

    /**
     * Revocation from the platform: the grant's own operator, or a platform administrator.
     */
    public function revokeForPlatform(SupportAccessGrant $grant, User $operatorUser, string $reason): void
    {
        $own = $grant->operator()->value('user_id') === $operatorUser->getKey() && $this->authorization->can($operatorUser, PlatformCapability::SupportManage);

        if (! $own && ! $this->authorization->can($operatorUser, PlatformCapability::TenantsManage)) {
            throw new DomainException('Only the grant\'s operator or a platform administrator can revoke it.');
        }

        AuditLog::asPlatformOperator($operatorUser, fn () => $this->revokeLocked($grant, $this->reason($reason), $operatorUser, 'platform'));
    }

    /**
     * Uses a grant: the operator opens one scope of the support workspace. Checked under the grant's
     * row lock and audited; refused the moment the grant is not active, has ended, was revoked, is
     * someone else's, or does not cover the scope — and for a tenant that is closed.
     */
    public function open(int $grantId, SupportScope $scope, User $operatorUser, string $action): SupportAccessGrant
    {
        $this->authorization->authorize($operatorUser, PlatformCapability::SupportManage);

        return DB::transaction(function () use ($grantId, $scope, $operatorUser, $action): SupportAccessGrant {
            /** @var SupportAccessGrant|null $locked */
            $locked = SupportAccessGrant::query()->withoutTenancy()->whereKey($grantId)->lockForUpdate()->first();

            $this->assertUsable($locked, $scope, $operatorUser);

            $locked->forceFill(['last_used_at' => now(), 'use_count' => $locked->use_count + 1])->save();

            TenantContext::current()->run((int) $locked->tenant_id, fn () => AuditLog::asPlatformOperator($operatorUser, fn () => AuditLog::record($locked, 'support_access_used', null, ['platform_operator_id' => $locked->platform_operator_id, 'scope' => $scope->value, 'action' => mb_substr($action, 0, 100)])));

            return $locked;
        });
    }

    /**
     * The grant as it is now, if $operatorUser may use it for $scope (assertUsable) — what the
     * support workspace loads on every request, so a page left open stops working when it ends.
     */
    public function usableGrant(int $grantId, SupportScope $scope, User $operatorUser): SupportAccessGrant
    {
        /** @var SupportAccessGrant|null $grant */
        $grant = SupportAccessGrant::query()->withoutTenancy()->find($grantId);
        $this->assertUsable($grant, $scope, $operatorUser);

        return $grant;
    }

    /**
     * The same checks as open(), without a lock or an audit row — re-run on every request and
     * Livewire update of the workspace, so a page left open stops working when the grant ends.
     */
    public function assertUsable(?SupportAccessGrant $grant, SupportScope $scope, User $operatorUser): void
    {
        $operatorId = $grant?->platform_operator_id;
        $ownerUserId = $operatorId !== null ? PlatformOperator::query()->whereKey($operatorId)->whereNull('revoked_at')->value('user_id') : null;
        $tenant = $grant !== null ? Tenant::query()->find($grant->tenant_id) : null;

        if ($grant === null || $ownerUserId !== $operatorUser->getKey() || ! $this->authorization->can($operatorUser, PlatformCapability::SupportManage)) {
            throw new DomainException('This support access is not yours.');
        }

        if ($tenant === null || ! $this->openForSupport($tenant)) {
            throw new DomainException('This organisation is closed to support access.');
        }

        if (! $grant->isActive()) {
            throw new DomainException('This support access is not active (expired, revoked, denied or not yet approved).');
        }

        if (! $grant->allows($scope)) {
            throw new DomainException("This support access does not cover {$scope->label()}.");
        }
    }

    /**
     * The operator's grant in the current tenant that is active right now, if any.
     */
    public function activeGrant(PlatformOperator $operator): ?SupportAccessGrant
    {
        return SupportAccessGrant::query()
            ->where('platform_operator_id', $operator->getKey())
            ->where('status', SupportGrantStatus::Active->value)
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();
    }

    /**
     * Records ended grants and lapsed requests (platform:sweep). Correctness never depends on it:
     * an ended grant already opens nothing.
     *
     * @return array{expired: int, lapsed: int}
     */
    public function expireDue(): array
    {
        $result = ['expired' => 0, 'lapsed' => 0];
        $lapseBefore = now()->subHours((int) config('platform.support.request_ttl_hours', 72));

        SupportAccessGrant::query()->withoutTenancy()
            ->where(fn ($query) => $query->where(fn ($active) => $active->where('status', SupportGrantStatus::Active->value)->where('expires_at', '<=', now()))
                ->orWhere(fn ($requested) => $requested->where('status', SupportGrantStatus::Requested->value)->where('requested_at', '<=', $lapseBefore)))
            // By id: each row leaves the filter as it is recorded, so offset pages would skip rows.
            ->lazyById(200)
            ->each(function (SupportAccessGrant $grant) use (&$result): void {
                DB::transaction(function () use ($grant, &$result): void {
                    $locked = $this->lockGrant($grant);
                    $ended = $locked->status === SupportGrantStatus::Active && $locked->expires_at !== null && ! $locked->expires_at->isFuture();
                    $lapsed = $locked->status === SupportGrantStatus::Requested;

                    if (! $ended && ! $lapsed) {
                        return;
                    }

                    $from = $locked->status;
                    $locked->forceFill(['status' => SupportGrantStatus::Expired])->save();
                    TenantContext::current()->run((int) $locked->tenant_id, fn () => AuditLog::record($locked, $ended ? 'support_access_expired' : 'support_access_request_lapsed', ['status' => $from->value], ['status' => SupportGrantStatus::Expired->value]));
                    $result[$ended ? 'expired' : 'lapsed']++;

                    $this->events->record($ended ? 'support.expired' : 'support.request_lapsed', PlatformEventSeverity::Info, $ended ? 'Support access expired' : 'Support request lapsed unanswered', Tenant::query()->find($locked->tenant_id), ['grant_id' => $locked->id], "support.expired:{$locked->id}");
                });
            });

        return $result;
    }

    private function revokeLocked(SupportAccessGrant $grant, string $reason, User $actor, string $source): void
    {
        DB::transaction(function () use ($grant, $reason, $actor, $source): void {
            Tenant::query()->whereKey($grant->tenant_id)->lockForUpdate()->first();
            $locked = $this->lockGrant($grant);

            if (! in_array($locked->status, [SupportGrantStatus::Active, SupportGrantStatus::Requested], true) || $locked->revoked_at !== null) {
                throw new DomainException('This support access was already revoked or has ended.');
            }

            $from = $locked->status;
            $locked->forceFill(['status' => SupportGrantStatus::Revoked, 'revoked_at' => now(), 'revoked_by' => $actor->getKey(), 'revocation_reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null])->save();

            TenantContext::current()->run((int) $locked->tenant_id, fn () => AuditLog::record($locked, 'support_access_revoked', ['status' => $from->value], ['status' => SupportGrantStatus::Revoked->value, 'source' => $source, 'by_user_id' => $actor->getKey()], $reason));
            Log::notice('identity.support_access_revoked', ['grant_id' => $locked->getKey(), 'actor_id' => $actor->getKey(), 'source' => $source]);
        });
    }

    private function lockGrant(SupportAccessGrant $grant): SupportAccessGrant
    {
        /** @var SupportAccessGrant */
        return SupportAccessGrant::query()->withoutTenancy()->whereKey($grant->getKey())->lockForUpdate()->firstOrFail();
    }

    private function assertTenantAdministrator(SupportAccessGrant $grant, User $actor): void
    {
        if (TenantContext::current()->id() !== (int) $grant->tenant_id) {
            throw new DomainException('This support request belongs to another organisation.');
        }

        if (! $actor->can('users.access.manage')) {
            throw new DomainException('Deciding on support access needs the users.access.manage permission.');
        }
    }

    private function supportOperator(User $operatorUser): PlatformOperator
    {
        return PlatformOperator::query()->where('user_id', $operatorUser->getKey())->where('role', PlatformRole::Support->value)->whereNull('revoked_at')->first()
            ?? throw new DomainException('Only an active platform support operator can request support access.');
    }

    /**
     * Support may help a tenant that is running or suspended — never one that is provisioning, closed
     * or being deleted.
     */
    private function openForSupport(Tenant $tenant): bool
    {
        return in_array($tenant->status, [TenantStatus::Trial, TenantStatus::Active, TenantStatus::PastDue, TenantStatus::Suspended], true);
    }

    /**
     * @param  list<SupportScope>  $scopes
     * @return list<SupportScope>
     */
    private function scopes(array $scopes): array
    {
        $scopes = array_values(array_unique($scopes, SORT_REGULAR));

        if ($scopes === [] || array_filter($scopes, fn (mixed $scope): bool => ! $scope instanceof SupportScope) !== []) {
            throw new DomainException('Choose at least one support scope.');
        }

        return $scopes;
    }

    private function minutes(int $minutes): void
    {
        $max = (int) config('platform.support.max_minutes', config('identity.support.max_minutes', 480));

        if ($minutes < 1 || $minutes > $max) {
            throw new DomainException("Support access lasts between 1 and {$max} minutes.");
        }
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('A reason is required.');
        }

        return mb_substr($reason, 0, 255);
    }

    private function notifyTenantAdministrators(Tenant $tenant, SupportAccessGrant $grant, User $operatorUser): void
    {
        TenantContext::current()->run($tenant, function () use ($grant, $operatorUser): void {
            User::query()->membersOfCurrentTenant(fn ($membership) => $membership->where('status', AccessState::Active->value))->get()
                ->filter(fn (User $user): bool => $user->can('users.access.manage'))
                ->each(fn (User $user) => app(NotificationDispatchService::class)->alert($user, 'Support', 'Support access requested', "{$operatorUser->name} (platform support) asks for access: {$grant->reason}", 'warning', null, "support-request:{$grant->id}"));
        });
    }
}
