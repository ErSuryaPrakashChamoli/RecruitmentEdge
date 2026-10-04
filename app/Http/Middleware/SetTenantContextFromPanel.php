<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Identity\TenantSelectionService;
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
 * SaaS-2: canAccessTenant() is the person's membership of THIS tenant (Active, usable tenant, a
 * role there, no employment block) — another tenant's membership or role never counts.
 */
class SetTenantContextFromPanel
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();
        $user = Filament::auth()->user();

        abort_unless($tenant instanceof Tenant && $user instanceof User && $user->canAccessTenant($tenant), 404);

        TenantContext::current()->setTenant($tenant);

        // SaaS-2: entering a tenant (sign-in, a switch) is audited once; the session only remembers
        // it — the URL's tenant and this membership check decide every request.
        app(TenantSelectionService::class)->entered($user, $tenant, $request->hasSession() ? $request->session() : null);

        return $next($request);
    }
}
