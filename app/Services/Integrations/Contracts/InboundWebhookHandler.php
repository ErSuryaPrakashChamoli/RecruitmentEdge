<?php

namespace App\Services\Integrations\Contracts;

use App\Models\InboundWebhookEvent;
use App\Models\IntegrationConnection;

/**
 * SaaS-6: what an inbound webhook connection does with a verified event. Runs on the queue, inside
 * the connection's tenant, attributed to the connection; returns a short, non-personal result.
 * Throwing InvalidPayload marks the event Ignored (retrying would not help); any other exception is
 * retried and then recorded as Failed.
 */
interface InboundWebhookHandler
{
    public function key(): string;

    public function label(): string;

    /**
     * @param  array<string, mixed>  $payload  the decoded body
     * @return array<string, mixed>
     */
    public function handle(IntegrationConnection $connection, InboundWebhookEvent $event, array $payload): array;
}
