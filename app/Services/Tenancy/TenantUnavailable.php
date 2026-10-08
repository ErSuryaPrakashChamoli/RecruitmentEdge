<?php

namespace App\Services\Tenancy;

use RuntimeException;

/**
 * SaaS-1: queued or scheduled work for a tenant that is missing, not usable (suspended,
 * cancelled, deleted…) or whose payload does not match its context. The work is not done.
 */
class TenantUnavailable extends RuntimeException
{
    public static function forTenant(int $tenantId, string $reason): self
    {
        return new self("Tenant {$tenantId} is unavailable for background work: {$reason}.");
    }
}
