<?php

namespace App\Services\Tenancy;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * SaaS-1: the platform's list of tenants for platform-level work — the scheduler's per-tenant
 * dispatch and the per-tenant health pass. Only tenants whose status allows background work.
 *
 * SaaS-7: enumerated lazily in id order (never the whole population in memory), optionally limited
 * to given ids (a work probe), optionally starting after an id (a rotating cursor).
 */
class TenantDirectory
{
    /**
     * @return Collection<int, Tenant>
     */
    public function forBackgroundWork(): Collection
    {
        return $this->lazyForBackgroundWork()->collect();
    }

    /**
     * @param  list<int>|null  $only  limit to these tenant ids (null: every tenant)
     * @return LazyCollection<int, Tenant>
     */
    public function lazyForBackgroundWork(?array $only = null, int $afterId = 0): LazyCollection
    {
        if ($only === []) {
            return LazyCollection::empty();
        }

        $statuses = array_map(fn (TenantStatus $status): string => $status->value, array_values(array_filter(TenantStatus::cases(), fn (TenantStatus $status): bool => $status->allowsBackgroundWork())));

        // SaaS-3: a trial past its end is filtered out here too (Tenant::effectiveStatus()).
        return Tenant::query()
            ->whereIn('status', $statuses)
            ->where('id', '>', $afterId)
            ->when($only !== null, fn ($query) => $query->whereIn('id', $only))
            ->lazyById(500)
            ->filter(fn (Tenant $tenant): bool => $tenant->allowsBackgroundWork())
            ->values();
    }
}
