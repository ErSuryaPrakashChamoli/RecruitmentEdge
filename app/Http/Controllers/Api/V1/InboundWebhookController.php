<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Webhooks\InboundWebhookReceiver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SaaS-6: a tenant's inbound webhook endpoint — authenticated by its signature, not a credential.
 */
class InboundWebhookController extends Controller
{
    public function __invoke(Request $request, string $publicKey, InboundWebhookReceiver $receiver): JsonResponse
    {
        return $receiver->receive($request, $publicKey);
    }
}
