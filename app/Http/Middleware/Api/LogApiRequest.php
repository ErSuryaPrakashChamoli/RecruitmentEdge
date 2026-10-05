<?php

namespace App\Http\Middleware\Api;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * SaaS-7 (C8): one structured line per API and inbound-hook request — `api.request` with the route
 * name, method, status, duration and the credential (by id) — so a log pipeline can chart API
 * volume, 4xx/401/403/429/5xx rates and latency per tenant (tenant_id and request_id come from the
 * log Context). Never the URL's query, a header, the body or the token.
 *
 * First in the API middleware stack, so refusals by the later middleware (413, 415, 401, 429) are
 * measured too.
 */
class LogApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);
        $response = $next($request);

        if ((bool) config('api.telemetry.log_requests', true)) {
            $principal = $request->attributes->get(AuthenticateApiCredential::PRINCIPAL);

            Log::info('api.request', [
                'route' => $request->route()?->getName(),
                'method' => $request->getMethod(),
                'status' => $response->getStatusCode(),
                'duration_ms' => (int) round((hrtime(true) - $started) / 1e6),
                'credential_id' => $principal?->credential->getKey(),
            ]);
        }

        return $response;
    }
}
