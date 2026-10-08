<?php

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SaaS-1: every HTTP request (Livewire updates included) starts with no tenant. The tenant is then
 * established only by an explicit resolver — the panel's tenant middleware (Filament tenancy and
 * membership), the careers/portal tenant in the URL, a signed record. A context set before the
 * request (a test's own tenant) is restored afterwards.
 */
class ResetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = TenantContext::current();
        $previous = $context->id();
        $context->clear();

        try {
            return $next($request);
        } finally {
            if ($previous !== null) {
                $context->setTenant($previous);
            }
        }
    }
}
