<?php

namespace App\Services\Platform\Commercial;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Identity\StaffAccessService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;

/**
 * SaaS-3: what every commercial change does besides the change itself, inside its transaction:
 * bump the tenant's entitlement version (the cache key — an old cached map is never read again),
 * invalidate in-process access decisions, and write the audit row in the TENANT's own stream (the
 * tenant sees its plan and status history) plus a platform log line. Never a secret.
 */
final class CommercialChange
{
    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>  $new
     */
    public static function record(Tenant $tenant, string $action, ?array $old, array $new, ?User $operator, string $source, ?string $reason = null): void
    {
        Tenant::query()->whereKey($tenant->getKey())->increment('entitlement_version');
        $tenant->refresh();

        StaffAccessService::invalidateDecisions();
        app(EntitlementService::class)->forget();

        TenantContext::current()->run($tenant, function () use ($tenant, $action, $old, $new, $operator, $source, $reason): void {
            AuditLog::record($tenant, $action, $old, [...$new, 'source' => $source, 'by_user_id' => $operator?->getKey()], $reason);
        });

        if (TenantContext::current()->id() === (int) $tenant->getKey()) {
            TenantContext::current()->setTenant($tenant);
        }

        Log::notice('platform.'.$action, ['tenant_id' => $tenant->getKey(), 'source' => $source, 'operator_id' => $operator?->getKey()]);
    }
}
