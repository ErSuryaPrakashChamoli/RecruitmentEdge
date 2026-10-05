<?php

namespace App\Services\Platform;

use App\Enums\AccessState;
use App\Enums\PlatformCapability;
use App\Enums\PlatformEventSeverity;
use App\Enums\TenantStatus;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * SaaS-5: moves a tenant's ownership (SaaS-3 tenant_memberships.is_owner, one per tenant) to another
 * member — a platform administrator's decision (platform.tenants.manage), never a tenant's.
 *
 * The new owner must be an active member of this tenant who already holds the protected CHRO role
 * there (grant it in the tenant first): ownership adds no role and removes none. The previous owner
 * keeps their membership and roles; only the owner flag moves. The identities are untouched.
 *
 * Atomic under the tenant row lock, then the memberships (in id order). $expectedOwner guards
 * against acting on a stale screen or a concurrent transfer: a different current owner refuses.
 * Audited in the tenant's stream (old → new, reason, operator) and the platform's event feed.
 */
class TenantOwnershipService
{
    public function __construct(
        private readonly PlatformAuthorization $authorization,
        private readonly PlatformEvents $events,
    ) {}

    public function transfer(Tenant $tenant, User $newOwner, string $reason, User $operator, ?User $expectedOwner = null): TenantMembership
    {
        $this->authorization->authorize($operator, PlatformCapability::TenantsManage);
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('A reason is required to transfer ownership.');
        }

        [$membership, $previousOwnerId] = AuditLog::asPlatformOperator($operator, fn (): array => TenantContext::current()->run($tenant, fn (): array => DB::transaction(function () use ($tenant, $newOwner, $reason, $expectedOwner): array {
            /** @var Tenant $locked */
            $locked = Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [TenantStatus::Provisioning, TenantStatus::Cancelled, TenantStatus::DeletionPending, TenantStatus::Deleted], true)) {
                throw new DomainException("A {$locked->status->label()} tenant's ownership cannot change.");
            }

            $memberships = TenantMembership::query()
                ->where('tenant_id', $locked->getKey())
                ->where(fn ($query) => $query->where('is_owner', true)->orWhere('user_id', $newOwner->getKey()))
                ->orderBy('id')->lockForUpdate()->get();

            /** @var TenantMembership|null $current */
            $current = $memberships->firstWhere('is_owner', true);
            /** @var TenantMembership|null $target */
            $target = $memberships->firstWhere('user_id', $newOwner->getKey());

            if ($expectedOwner !== null && (int) $current?->user_id !== (int) $expectedOwner->getKey()) {
                throw new DomainException('The owner changed meanwhile: reload and check before transferring.');
            }

            if ($target === null || $target->status !== AccessState::Active) {
                throw new DomainException('The new owner must be an active member of this organisation.');
            }

            if (! $newOwner->fresh()->hasRole(Role::byKeyOrFail((string) config('identity.chro_role')))) {
                throw new DomainException('The new owner must already hold the CHRO role in this organisation.');
            }

            if ($current?->is($target)) {
                return [$target, $current->user_id];
            }

            $current?->forceFill(['is_owner' => null])->save();
            $target->forceFill(['is_owner' => true])->save();

            AuditLog::record($target, $current === null ? 'ownership_assigned' : 'ownership_transferred', ['owner_user_id' => $current?->user_id], ['owner_user_id' => $newOwner->getKey()], $reason);

            return [$target, $current?->user_id];
        })));

        if ((int) $previousOwnerId !== (int) $newOwner->getKey()) {
            $this->events->record('tenant.ownership_transferred', PlatformEventSeverity::Warning, "Ownership of {$tenant->slug} transferred", $tenant, ['from_user_id' => $previousOwnerId, 'to_user_id' => $newOwner->getKey(), 'operator_user_id' => $operator->getKey()]);
        }

        return $membership;
    }

    /**
     * The tenant's current owner, if any (tenants that predate SaaS-3 have none).
     */
    public function owner(Tenant $tenant): ?User
    {
        $userId = TenantMembership::query()->where('tenant_id', $tenant->getKey())->where('is_owner', true)->value('user_id');

        return $userId !== null ? User::query()->find($userId) : null;
    }
}
