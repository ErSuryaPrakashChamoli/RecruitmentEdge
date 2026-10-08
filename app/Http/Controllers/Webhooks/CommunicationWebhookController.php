<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Communication\CommunicationProviderManager;
use App\Services\Communication\DeliveryStatusService;
use App\Services\Communication\Providers\WhatsAppCloudProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Provider delivery-status / opt-out callbacks (Phase 5). Nothing in a payload is trusted until
 * the provider's signature over the raw request verifies; each provider event id is processed at
 * most once (DeliveryStatusService). Responds 200 for duplicates so providers stop retrying.
 */
class CommunicationWebhookController extends Controller
{
    public function verify(Request $request, string $provider, CommunicationProviderManager $providers): Response
    {
        $handler = $providers->find($provider);

        $challenge = $handler instanceof WhatsAppCloudProvider ? $handler->verificationChallenge($request) : null;

        abort_if($challenge === null, 403);

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function handle(Request $request, string $provider, CommunicationProviderManager $providers, DeliveryStatusService $statuses): JsonResponse
    {
        $handler = $providers->webhookHandler($provider);
        $channelProvider = $providers->find($provider);

        abort_if($handler === null || $channelProvider === null, 404);

        if (! $handler->verifyWebhook($request)) {
            Log::warning('Rejected communication webhook with an invalid signature', ['provider' => $provider, 'ip' => $request->ip()]);

            abort(401);
        }

        $hash = hash('sha256', $request->getContent());
        $results = [];

        foreach ($handler->parseWebhook($request) as $update) {
            $results[] = $statuses->apply($provider, $update, $hash, $channelProvider->channel());
        }

        return response()->json(['received' => count($results)]);
    }
}
