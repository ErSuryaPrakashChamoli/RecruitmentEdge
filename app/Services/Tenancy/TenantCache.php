<?php

namespace App\Services\Tenancy;

/**
 * SaaS-1: every cache entry or lock that holds or guards tenant data is keyed under its tenant —
 * t:{tenant_id}:{key} — so one tenant can never read, overwrite or be blocked by another tenant's
 * entry. Keys that are global on purpose (shared provider credentials and circuit breakers,
 * worker and scheduler heartbeats, keys made only of global record ids) are listed in
 * docs/saas-1-tenant-foundation.md.
 */
final class TenantCache
{
    public static function key(string $key, ?int $tenantId = null): string
    {
        return 't:'.($tenantId ?? TenantContext::current()->requireId()).':'.$key;
    }
}
