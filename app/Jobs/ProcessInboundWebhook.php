<?php

namespace App\Jobs;

use App\Services\Webhooks\InboundWebhookProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * SaaS-6: processes one stored inbound webhook event (InboundWebhookProcessor). A tenant job; the
 * event is claimed atomically, so a duplicate job is a no-op. Unexpected errors are retried, then
 * the event is recorded as Failed (reprocessable by an administrator).
 */
class ProcessInboundWebhook implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public function __construct(public readonly int $eventId)
    {
        $this->onQueue('integrations');
    }

    public function tries(): int
    {
        return (int) config('api.webhooks.inbound_attempts', 3);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(InboundWebhookProcessor $processor): void
    {
        $processor->process($this->eventId);
    }

    public function failed(?Throwable $exception): void
    {
        app(InboundWebhookProcessor::class)->markFailed($this->eventId, 'Processing failed ('.($exception !== null ? class_basename($exception) : 'unknown').').');
    }
}
