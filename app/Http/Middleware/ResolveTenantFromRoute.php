<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SaaS-1: public tenant surfaces (careers site, candidate portal) name their tenant in the path —
 * /careers/{tenant}/…, /portal/{tenant}/… — a platform-assigned slug looked up in `tenants`, never
 * the Host header. An unknown or unusable tenant is a plain 404. The parameter is then removed
 * from the route, so controllers keep their own parameters, and every lookup that follows (route
 * model binding, the candidate guard, controllers) runs inside that tenant only.
 *
 * Registered in the middleware priority list ahead of EnsureCandidateSessionIsCurrent,
 * authentication and SubstituteBindings, which all read tenant-owned rows.
 */
class ResolveTenantFromRoute
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $slug = $route?->parameter('tenant');
        $slug = $slug instanceof Tenant ? $slug->slug : $slug;

        $tenant = is_string($slug) ? Tenant::query()->where('slug', $slug)->first() : null;

        abort_unless($tenant instanceof Tenant && $tenant->isUsable(), 404);

        TenantContext::current()->setTenant($tenant);
        $route->forgetParameter('tenant');

        return $next($request);
    }
}
