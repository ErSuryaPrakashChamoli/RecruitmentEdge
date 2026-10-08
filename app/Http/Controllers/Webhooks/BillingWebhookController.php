<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Billing\BillingWebhookIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SaaS-4: payment provider notifications. Authenticated by the provider's signature (never the
 * session), stored once per event id, acknowledged, processed on the queue (BillingWebhookIngestor).
 */
class BillingWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, BillingWebhookIngestor $ingestor): JsonResponse
    {
        [$status, $body] = $ingestor->receive($provider, $request);

        return response()->json($body, $status);
    }
}
