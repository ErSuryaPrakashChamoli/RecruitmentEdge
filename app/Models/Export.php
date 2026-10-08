<?php

namespace App\Models;

use App\Enums\Entitlement;
use App\Models\Concerns\BelongsToTenant;
use App\Services\Entitlements\EntitlementService;
use App\Services\Tenancy\TenantStorage;
use Filament\Actions\Exports\Models\Export as FilamentExport;

/**
 * SaaS-1: Filament's export record, owned by the tenant it was requested in. Filament resolves its
 * Export model through the container (TenancyServiceProvider binds this class), so exports are
 * created, listed, processed by the queued export jobs and downloaded inside one tenant.
 */
class Export extends FilamentExport
{
    use BelongsToTenant;

    /**
     * SaaS-3: a table export starts only when data exports are in the tenant's plan — checked on
     * the record itself, so no hidden button, Livewire call or job can start one without it.
     */
    protected static function booted(): void
    {
        static::creating(fn () => app(EntitlementService::class)->require(Entitlement::ExportsData));
    }

    /**
     * SaaS-1: the export's files live under its own tenant's storage prefix.
     */
    public function getFileDirectory(): string
    {
        return TenantStorage::ROOT.'/'.$this->tenant_id.'/'.parent::getFileDirectory();
    }
}
