<?php

namespace App\Http\Controllers;

use App\Services\Operations\ReadinessProbe;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SaaS-7 (C9): liveness and readiness, separated.
 *
 * - GET /health/live: the process answers — no dependency is touched, so a database outage never
 *   gets a healthy web container restarted.
 * - GET /health/ready: the dependencies every request needs (ReadinessProbe). Public answer:
 *   {"status": "ok"|"unavailable"} only. With the queue-health bearer token, also which check
 *   failed (names and booleans — never messages, hosts or paths).
 *
 * Both are registered outside the web middleware group (no session, cookie or CSRF), throttled,
 * and platform-level (no tenant).
 */
class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function ready(Request $request, ReadinessProbe $probe): JsonResponse
    {
        $result = TenantContext::current()->runWithoutTenant(fn (): array => $probe->run());
        $body = ['status' => $result['ok'] ? 'ok' : 'unavailable'];

        if ($this->operator($request)) {
            $body['checks'] = $result['checks'];
        }

        return response()->json($body, $result['ok'] ? 200 : 503);
    }

    private function operator(Request $request): bool
    {
        $token = (string) config('queue.health_token');

        return $token !== '' && $request->bearerToken() !== null && hash_equals($token, $request->bearerToken());
    }
}
