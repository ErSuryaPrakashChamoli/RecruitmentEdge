<?php

namespace App\Services\Billing;

use App\Enums\BillingEventStatus;
use App\Enums\BillingEventType;
use App\Models\AuditLog;
use App\Models\BillingCustomer;
use App\Models\BillingEvent;
use App\Services\Billing\Providers\BillingProviderManager;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * SaaS-4: applies one stored provider event, exactly once: normalised by its adapter, matched to
 * a local payment by the provider's references (BillingReferenceResolver), then — inside that
 * payment's tenant, under the billing locks — handed to PaymentService, which only moves state
 * forward. Duplicate, concurrent, late or out-of-order deliveries therefore converge on the same
 * result. An event naming a payment we have not recorded yet is retried (BillingReferencePending)
 * before it is set aside; reconciliation picks up anything left.
 */
class BillingEventProcessor
{
    public function __construct(
        private readonly BillingProviderManager $providers,
        private readonly BillingReferenceResolver $references,
        private readonly PaymentService $payments,
    ) {}

    /**
     * @throws BillingReferencePending
     */
    public function process(int $eventId, bool $finalAttempt = true): string
    {
        /** @var BillingEvent|null $event */
        $event = BillingEvent::query()->find($eventId);

        if ($event === null || in_array($event->status, [BillingEventStatus::Processed, BillingEventStatus::Ignored], true)) {
            return 'already handled';
        }

        BillingEvent::query()->whereKey($eventId)->increment('attempts');
        $provider = $this->providers->find($event->provider);

        if ($provider === null || $event->payload === null) {
            return $this->setAside($event, $provider === null ? 'provider not available' : 'payload no longer kept');
        }

        try {
            $normalized = $provider->normalize($event->payload);
        } catch (InvalidArgumentException) {
            return $this->setAside($event, 'unreadable payload');
        }

        if ($normalized->type === BillingEventType::Unsupported || $normalized->payment === null) {
            return $this->setAside($event, 'unsupported event');
        }

        $target = $this->references->payment($event->provider, $normalized->payment->providerPaymentRef, $normalized->payment->reference);

        if ($target === null) {
            if (! $finalAttempt) {
                throw new BillingReferencePending("Event {$event->provider_event_id} names a payment not recorded yet.");
            }

            return $this->setAside($event, 'unknown payment reference');
        }

        return TenantContext::current()->run($target['tenant_id'], fn (): string => DB::transaction(function () use ($eventId, $target, $normalized): string {
            BillingLock::tenant($target['tenant_id']);

            /** @var BillingEvent $locked */
            $locked = BillingEvent::query()->whereKey($eventId)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [BillingEventStatus::Processed, BillingEventStatus::Ignored], true)) {
                return 'already handled';
            }

            [$subscription, $invoice, $payment] = BillingLock::payment($target['payment_id']);

            // The event must concern this tenant's own provider customer.
            $customerRef = BillingCustomer::query()->whereKey($subscription->billing_customer_id)->value('provider_customer_ref');
            $result = $normalized->providerCustomerRef !== null && $normalized->providerCustomerRef !== $customerRef
                ? 'customer mismatch'
                : $this->payments->applyOutcome($subscription, $invoice, $payment, $normalized->payment, 'webhook');

            $locked->forceFill([
                'tenant_id' => $target['tenant_id'],
                'status' => $result === 'applied' ? BillingEventStatus::Processed : BillingEventStatus::Ignored,
                'processed_at' => now(),
                'note' => $result === 'applied' ? null : $result,
            ])->save();

            AuditLog::record($locked, 'billing_webhook_processed', null, ['event' => $locked->provider_event_id, 'type' => $normalized->type->value, 'result' => $result, 'payment' => $payment->reference]);

            return $result;
        }));
    }

    private function setAside(BillingEvent $event, string $why): string
    {
        BillingEvent::query()->whereKey($event->id)->whereIn('status', [BillingEventStatus::Received->value, BillingEventStatus::Failed->value])->update(['status' => BillingEventStatus::Ignored->value, 'processed_at' => now(), 'note' => $why, 'updated_at' => now()]);
        Log::notice('billing.webhook_set_aside', ['provider' => $event->provider, 'event' => $event->provider_event_id, 'why' => $why]);

        return $why;
    }
}
