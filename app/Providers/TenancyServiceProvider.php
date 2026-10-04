<?php

namespace App\Providers;

use App\Models\Export;
use App\Models\FailedImportRow;
use App\Models\Import;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantQueueGuard;
use Filament\Actions\Exports\Models\Export as FilamentExport;
use Filament\Actions\Imports\Models\FailedImportRow as FilamentFailedImportRow;
use Filament\Actions\Imports\Models\Import as FilamentImport;
use Filament\Events\TenantSet;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

/**
 * SaaS-1: tenant isolation wiring (docs/saas-1-tenant-foundation.md).
 */
class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One context per request and per queued job (the worker forgets scoped instances).
        $this->app->scoped(TenantContext::class);

        // Filament builds its export / import records through the container: use the
        // tenant-owned models.
        $this->app->bind(FilamentExport::class, Export::class);
        $this->app->bind(FilamentImport::class, Import::class);
        $this->app->bind(FilamentFailedImportRow::class, FailedImportRow::class);
    }

    public function boot(): void
    {
        // Filament's tenant selection (IdentifyTenant, or Filament::setTenant() in tests) becomes
        // the TenantContext; SetTenantContextFromPanel re-checks access on every panel request.
        Event::listen(TenantSet::class, function (TenantSet $event): void {
            $tenant = $event->getTenant();

            if ($tenant instanceof Tenant) {
                TenantContext::current()->setTenant($tenant);
            }
        });

        // Every queued payload declares its tenant; Context (which carries it) is restored by the
        // framework before JobProcessing listeners registered here run.
        Queue::createPayloadUsing(fn (): array => app(TenantQueueGuard::class)->payload());
        Event::listen(JobProcessing::class, fn (JobProcessing $event) => app(TenantQueueGuard::class)->check($event->job));
    }
}
