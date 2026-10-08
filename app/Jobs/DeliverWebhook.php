<?php

namespace App\Jobs;

use App\Services\Webhooks\WebhookDeliveryService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SaaS-6: one attempt at one outbound webhook delivery (WebhookDeliveryService). A tenant job: it
 * runs only while its tenant may run background work (TenantQueueGuard). Retries are scheduled by
 * the service, not the queue, and the delivery is claimed atomically, so a duplicate job is a no-op.
 */
class DeliverWebhook implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /**
     * SaaS-7 (C4): one queued job per delivery — integrations:sweep re-dispatching a due delivery
     * whose job is still waiting adds nothing (it used to multiply a backlog). Released as the job
     * starts, so a scheduled retry can be queued from inside it.
     */
    public int $uniqueFor = 3600;

    public int $tries = 1;

    /**
     * Only used if $tries is ever raised: the service schedules the real retries.
     */
    public int $backoff = 60;

    public int $timeout = 60;

    public function __construct(public readonly int $deliveryId)
    {
        $this->onQueue('integrations');
    }

    public function uniqueId(): string
    {
        return (string) $this->deliveryId;
    }

    public function handle(WebhookDeliveryService $deliveries): void
    {
        $deliveries->deliver($this->deliveryId);
    }

    /**
     * The delivery stays claimed until its lease ends, then integrations:sweep retries it; a paused
     * tenant's job is queued again by SaaS-3 (PausedTenantWork) once the tenant is usable.
     */
    public function failed(?Throwable $exception): void
    {
        Log::warning('webhooks.delivery_job_failed', ['delivery_id' => $this->deliveryId, 'exception' => $exception !== null ? $exception::class : null]);
    }
}
