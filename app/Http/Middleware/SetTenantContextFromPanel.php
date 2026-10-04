<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SaaS-1: panel tenant middleware (persistent — Livewire updates run it too). Filament's
 * IdentifyTenant has already resolved the {tenant} slug and checked User::canAccessTenant()
 * (active membership, usable tenant, a role there); this re-checks and makes it the request's
 * TenantContext, which every tenant-owned query, role check, job and cache key then uses.
 * Filament's selection alone is never the boundary: models enforce TenantScope independently.
 */
class SetTenantContextFromPanel
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();
        $user = Filament::auth()->user();

        abort_unless($tenant instanceof Tenant && $user !== null && method_exists($user, 'canAccessTenant') && $user->canAccessTenant($tenant), 404);

        TenantContext::current()->setTenant($tenant);

        return $next($request);
    }
}
