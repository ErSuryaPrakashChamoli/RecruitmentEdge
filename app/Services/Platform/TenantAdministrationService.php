<?php

namespace App\Services\Platform;

use App\Enums\PlatformCapability;
use App\Enums\PlatformEventSeverity;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Platform\Commercial\TenantLifecycleService;

/**
 * SaaS-5: a platform administrator's lifecycle actions on a tenant from the platform panel —
 * authorised (platform.tenants.manage), attributed to the operator in the tenant's audit stream,
 * and raised as platform events. The lifecycle itself stays SaaS-3's (TenantLifecycleService:
 * the transition table, the tenant row lock, paused work).
 */
class TenantAdministrationService
{
    public function __construct(
        private readonly PlatformAuthorization $authorization,
        private readonly TenantLifecycleService $lifecycle,
        private readonly PlatformEvents $events,
    ) {}

    public function suspend(Tenant $tenant, string $reason, User $operator): Tenant
    {
        return $this->change($tenant, $operator, 'tenant.suspended', PlatformEventSeverity::Warning, fn (): Tenant => $this->lifecycle->suspend($tenant, $reason, $operator));
    }

    public function activate(Tenant $tenant, string $reason, User $operator): Tenant
    {
        return $this->change($tenant, $operator, 'tenant.activated', PlatformEventSeverity::Info, fn (): Tenant => $this->lifecycle->activate($tenant, $reason, $operator));
    }

    public function cancel(Tenant $tenant, string $reason, User $operator): Tenant
    {
        return $this->change($tenant, $operator, 'tenant.cancelled', PlatformEventSeverity::Critical, fn (): Tenant => $this->lifecycle->cancel($tenant, $reason, $operator));
    }

    public function extendTrial(Tenant $tenant, int $days, string $reason, User $operator): Tenant
    {
        return $this->change($tenant, $operator, 'tenant.trial_extended', PlatformEventSeverity::Info, fn (): Tenant => $this->lifecycle->extendTrial($tenant, $days, $reason, $operator));
    }

    /**
     * @param  callable(): Tenant  $work
     */
    private function change(Tenant $tenant, User $operator, string $event, PlatformEventSeverity $severity, callable $work): Tenant
    {
        $this->authorization->authorize($operator, PlatformCapability::TenantsManage);
        $changed = AuditLog::asPlatformOperator($operator, $work);
        $this->events->record($event, $severity, "{$changed->slug}: {$changed->status->label()}", $changed, ['operator_user_id' => $operator->getKey(), 'status_reason' => $changed->status_reason]);

        return $changed;
    }
}
