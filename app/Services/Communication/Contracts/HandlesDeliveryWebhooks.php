<?php

namespace App\Services\Communication\Contracts;

use App\Services\Communication\Data\WebhookStatusUpdate;
use Illuminate\Http\Request;

/**
 * A provider that reports delivery status / opt-outs by webhook. verify() MUST authenticate the
 * request (signature over the raw body) before parse() is trusted.
 */
interface HandlesDeliveryWebhooks
{
    public function verifyWebhook(Request $request): bool;

    /**
     * @return array<int, WebhookStatusUpdate>
     */
    public function parseWebhook(Request $request): array;
}
