<?php

namespace App\Services\Platform\Commercial;

use App\Enums\PlatformRole;
use App\Models\User;
use App\Services\Platform\PlatformAccess;
use DomainException;

/**
 * SaaS-3: who may change the commercial control plane (plans, assignments, overrides, lifecycle,
 * provisioning). A platform administrator (PlatformAccess), or the platform's own console commands
 * and internal services, which pass no operator. Tenant administrators never can: no tenant route,
 * page or action calls these services (architecture test).
 */
final class PlatformOperatorGate
{
    public static function assert(?User $operator): void
    {
        if ($operator !== null && ! app(PlatformAccess::class)->holds($operator, PlatformRole::Administrator)) {
            throw new DomainException('Only a platform administrator can change plans, entitlements or a tenant\'s lifecycle.');
        }
    }
}
