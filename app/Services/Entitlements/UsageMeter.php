<?php

namespace App\Services\Entitlements;

use App\Enums\AccessState;
use App\Enums\Entitlement;
use App\Enums\RequisitionStatus;
use App\Models\RecruitmentRequisition;
use App\Models\TenantMembership;
use App\Services\Tenancy\TenantContext;
use LogicException;

/**
 * SaaS-3: the authoritative usage of every limit, counted from the records themselves in the
 * current tenant — no counter that could drift. With $locking the count is a locking read (the
 * latest committed rows, whatever snapshot the transaction holds), for use under the tenant lock.
 */
class UsageMeter
{
    public function current(Entitlement $entitlement, bool $locking = false): int
    {
        $tenantId = TenantContext::current()->requireId();

        $query = match ($entitlement) {
            // Closed and cancelled are terminal: active requisitions only grow by creation.
            Entitlement::RequisitionsActiveMax => RecruitmentRequisition::query()->whereNotIn('status', [RequisitionStatus::Closed->value, RequisitionStatus::Cancelled->value]),
            // A seat is a membership whose access is Active; suspended and revoked members hold none.
            Entitlement::MembersActiveMax => TenantMembership::query()->where('tenant_id', $tenantId)->where('status', AccessState::Active->value),
            default => throw new LogicException("{$entitlement->value} is not a limit."),
        };

        return $locking ? $query->sharedLock()->count() : $query->count();
    }
}
