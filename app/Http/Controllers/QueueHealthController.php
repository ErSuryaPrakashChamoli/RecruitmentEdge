<?php

namespace App\Http\Controllers;

use App\Services\QueueHealthService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 8.7 (D8.7-021): queue health as JSON for external monitoring. Never public. Counts and ages
 * only — no payloads or personal data. Responds 503 while anything needs attention, so an uptime
 * check can alert on the status code alone.
 *
 * SaaS-1: this is the platform view (every tenant's queues), so only the monitor holding the
 * QUEUE_HEALTH_TOKEN bearer token may read it. settings.manage is a tenant permission now; a
 * tenant's administrators see their own tenant's queue health on the panel's Queue health page.
 */
class QueueHealthController extends Controller
{
    public function __invoke(Request $request, QueueHealthService $health): JsonResponse
    {
        abort_unless($this->authorised($request), $request->bearerToken() === null ? 401 : 403);

        $snapshot = TenantContext::current()->runWithoutTenant(fn (): array => $health->snapshot());

        return response()->json($snapshot, $snapshot['status'] === 'ok' ? 200 : 503);
    }

    private function authorised(Request $request): bool
    {
        $token = (string) config('queue.health_token');

        return $token !== '' && $request->bearerToken() !== null && hash_equals($token, $request->bearerToken());
    }
}
