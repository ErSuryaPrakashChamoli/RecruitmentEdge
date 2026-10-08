<?php

namespace App\Jobs;

use App\Enums\BillingEventStatus;
use App\Models\BillingEvent;
use App\Services\Billing\BillingEventProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SaaS-4: processes one stored payment provider event (BillingEventProcessor). Queued with no tenant
 * (the event's tenant is not known when it arrives): the processor finds it from the provider's
 * references and works inside it. Idempotent — retries and duplicate jobs apply an event once.
 */
class ProcessBillingEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public function __construct(public readonly int $eventId)
    {
        $this->onQueue((string) config('billing.webhooks.queue', 'integrations'));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 60, 120, 300, 600];
    }

    public function handle(BillingEventProcessor $processor): void
    {
        $processor->process($this->eventId, finalAttempt: $this->attempts() >= $this->tries);
    }

    public function failed(?Throwable $exception): void
    {
        BillingEvent::query()->whereKey($this->eventId)->where('status', BillingEventStatus::Received->value)->update(['status' => BillingEventStatus::Failed->value, 'note' => mb_substr('Processing failed: '.($exception !== null ? $exception::class : 'unknown'), 0, 255), 'updated_at' => now()]);
        Log::error('billing.event_failed', ['event_id' => $this->eventId, 'exception' => $exception !== null ? $exception::class : null]);
    }
}
