<?php

namespace App\Http\Middleware;

use App\Models\Export;
use App\Models\Import;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SaaS-1: Filament's export and failed-import-row downloads are system routes outside the tenant
 * panel (/filament/exports/{export}/download). The record names its tenant: it is looked up by id
 * across tenants only to learn that tenant, the signed-in person must be able to act there, and
 * the request then runs inside it — route model binding, ExportPolicy (owner within 24 h) and the
 * download audit all see that tenant only. Anything else is a 404.
 */
class ResolveTenantForFilamentDownload
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $record = match (true) {
            $route?->hasParameter('export') => Export::query()->withoutTenancy()->find($this->key($route->parameter('export'))),
            $route?->hasParameter('import') => Import::query()->withoutTenancy()->find($this->key($route->parameter('import'))),
            default => null,
        };

        $tenant = $record === null ? null : Tenant::query()->find($record->getAttribute('tenant_id'));
        $user = $request->user('web');

        abort_unless($tenant instanceof Tenant && $user instanceof User && $user->canAccessTenant($tenant), 404);

        TenantContext::current()->setTenant($tenant);

        return $next($request);
    }

    private function key(mixed $parameter): mixed
    {
        return is_object($parameter) && method_exists($parameter, 'getKey') ? $parameter->getKey() : $parameter;
    }
}
