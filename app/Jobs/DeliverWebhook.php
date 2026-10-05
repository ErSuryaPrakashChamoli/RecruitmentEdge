<?php

namespace App\Jobs;

use App\Services\Webhooks\WebhookDeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * SaaS-6: one attempt at one outbound webhook delivery (WebhookDeliveryService). A tenant job: it
 * runs only while its tenant may run background work (TenantQueueGuard). Retries are scheduled by
 * the service, not the queue, and the delivery is claimed atomically, so a duplicate job is a no-op.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $deliveryId)
    {
        $this->onQueue('integrations');
    }

    public function handle(WebhookDeliveryService $deliveries): void
    {
        $deliveries->deliver($this->deliveryId);
    }
}
