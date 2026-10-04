<?php

namespace App\Services\Tenancy;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Support\Collection;

/**
 * SaaS-1: the platform's list of tenants for platform-level work — the scheduler's per-tenant
 * dispatch and the per-tenant health pass. Only tenants whose status allows background work.
 */
class TenantDirectory
{
    /**
     * @return Collection<int, Tenant>
     */
    public function forBackgroundWork(): Collection
    {
        $statuses = array_values(array_filter(TenantStatus::cases(), fn (TenantStatus $status): bool => $status->allowsBackgroundWork()));

        // SaaS-3: a trial past its end is filtered out here too (Tenant::effectiveStatus()).
        return Tenant::query()->whereIn('status', $statuses)->orderBy('id')->get()->filter(fn (Tenant $tenant): bool => $tenant->allowsBackgroundWork())->values();
    }
}
