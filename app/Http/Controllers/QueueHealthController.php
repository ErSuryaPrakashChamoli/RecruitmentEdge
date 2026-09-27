<?php

namespace App\Http\Controllers;

use App\Services\PlatformAlertService;
use App\Services\QueueHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 8.7 (D8.7-021): queue health as JSON for external monitoring. Never public: a signed-in
 * platform administrator (settings.manage), or a monitor presenting the QUEUE_HEALTH_TOKEN bearer
 * token when one is configured. Counts and ages only — no payloads or personal data. Responds 503
 * while anything needs attention, so an uptime check can alert on the status code alone.
 */
class QueueHealthController extends Controller
{
    public function __invoke(Request $request, QueueHealthService $health): JsonResponse
    {
        abort_unless($this->authorised($request), $request->user() === null && $request->bearerToken() === null ? 401 : 403);

        $snapshot = $health->snapshot();

        return response()->json($snapshot, $snapshot['status'] === 'ok' ? 200 : 503);
    }

    private function authorised(Request $request): bool
    {
        $token = (string) config('queue.health_token');

        if ($token !== '' && $request->bearerToken() !== null && hash_equals($token, $request->bearerToken())) {
            return true;
        }

        return (bool) $request->user()?->can(PlatformAlertService::PERMISSION);
    }
}
