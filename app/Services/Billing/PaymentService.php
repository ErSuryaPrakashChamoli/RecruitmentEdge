<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionSource;
use App\Models\AuditLog;
use App\Models\BillingCustomer;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingSubscription;
use App\Models\User;
use App\Services\Billing\Providers\BillingProviderManager;
use App\Services\Billing\Providers\PaymentRequest;
use App\Services\Billing\Providers\ProviderPaymentState;
use App\Services\Billing\Providers\ProviderUnavailable;
use App\Services\Platform\Commercial\PlatformOperatorGate;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * SaaS-4: payments — collected through the provider, or recorded manually by the platform for
 * manual and contract billing — and their outcomes. The invoice's amount is ours: a provider's
 * reported amount is compared with it, never trusted to settle a different one. A payment's state
 * only moves forward (PaymentStatus::rank): a late or replayed outcome changes nothing.
 *
 * Provider calls happen outside the billing locks (never hold the commercial lock across a network
 * call) and carry idempotency keys; an unreachable provider leaves everything as it was.
 */
class PaymentService
{
    public function __construct(
        private readonly BillingProviderManager $providers,
        private readonly SubscriptionOutcomes $outcomes,
        private readonly BillingStatusService $status,
    ) {}

    /**
     * Asks the provider to collect what is due on $invoice (provider billing only). Idempotent per
     * attempt: an attempt still pending is returned, never doubled.
     */
    public function collect(BillingInvoice $invoice): ?BillingPayment
    {
        $subscription = BillingSubscription::query()->findOrFail($invoice->billing_subscription_id);

        if ($subscription->source !== SubscriptionSource::Provider || $invoice->status !== InvoiceStatus::Open || $invoice->amount_due_minor === 0) {
            return null;
        }

        $provider = $this->providers->default();
        $customer = BillingCustomer::query()->findOrFail($subscription->billing_customer_id);

        if ($customer->provider_customer_ref === null) {
            throw new DomainException('The billing customer has no provider account yet.');
        }

        // 1. Our attempt, recorded first (its reference is the idempotency key the provider echoes).
        $payment = DB::transaction(function () use ($invoice, $subscription, $provider): BillingPayment {
            BillingLock::tenant((int) $invoice->tenant_id);
            BillingLock::subscription((int) $subscription->id);
            /** @var BillingInvoice $locked */
            $locked = BillingInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            /** @var BillingPayment|null $pending */
            $pending = $locked->payments()->where('status', PaymentStatus::Pending->value)->first();

            if ($pending !== null || $locked->status !== InvoiceStatus::Open) {
                return $pending ?? throw new DomainException('The invoice is no longer due.');
            }

            $payment = BillingPayment::query()->create([
                'billing_invoice_id' => $locked->id,
                'reference' => (string) Str::uuid(),
                'method' => 'provider',
                'provider' => $provider->name(),
                'status' => PaymentStatus::Pending,
                'currency' => $locked->currency,
                'amount_minor' => $locked->amount_due_minor,
                'attempted_at' => now(),
            ]);
            AuditLog::record($payment, 'payment_attempted', null, ['invoice' => $locked->number, 'amount' => $payment->amount()->decimal(), 'currency' => $payment->currency, 'provider' => $provider->name()]);
            $this->status->touch((int) $locked->tenant_id);

            return $payment;
        });

        if ($payment->provider_payment_ref !== null) {
            return $payment;
        }

        // 2. The provider call, outside the locks.
        try {
            $state = $provider->collect(new PaymentRequest($customer->provider_customer_ref, $payment->amount(), (string) $invoice->number, $payment->reference, 'payment-'.$payment->reference));
        } catch (ProviderUnavailable $e) {
            // The outcome is unknown: the attempt stays pending until an event or reconciliation.
            Log::warning('billing.collection_unavailable', ['tenant_id' => $payment->tenant_id, 'payment_id' => $payment->id, 'exception' => $e::class]);

            return $payment;
        }

        // 3. What the provider answered, applied like any other outcome.
        return DB::transaction(function () use ($payment, $state): BillingPayment {
            BillingLock::tenant((int) $payment->tenant_id);
            [$subscription, $invoice, $locked] = BillingLock::payment((int) $payment->id);
            $this->applyOutcome($subscription, $invoice, $locked, $state, 'provider');

            return $locked;
        });
    }

    /**
     * Applies a provider's statement about a payment. Caller: inside a transaction, the tenant,
     * subscription, invoice and payment locked in that order (BillingLock::payment).
     *
     * @return string what happened: applied, or why nothing was
     */
    public function applyOutcome(BillingSubscription $subscription, BillingInvoice $invoice, BillingPayment $payment, ProviderPaymentState $state, string $source): string
    {
        if ($payment->provider_payment_ref !== null && $payment->provider_payment_ref !== $state->providerPaymentRef) {
            return $this->refused($payment, 'reference mismatch', $source);
        }

        if ($payment->provider_payment_ref === null && $payment->method === 'provider') {
            $payment->forceFill(['provider_payment_ref' => $state->providerPaymentRef])->save();
        }

        $result = match ($state->status) {
            PaymentStatus::Succeeded => $this->succeeded($subscription, $invoice, $payment, $state, $source),
            PaymentStatus::Failed => $this->failed($subscription, $invoice, $payment, $state, $source),
            PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded => $this->refunded($payment, $state, $source),
            PaymentStatus::Pending => 'stale',
        };

        $this->status->touch((int) $payment->tenant_id);

        return $result;
    }

    /**
     * Records a payment received outside the provider (bank transfer, cheque) for manual or contract
     * billing — explicit, with the external reference and a reason, audited. Platform only.
     */
    public function recordManualPayment(BillingInvoice $invoice, Money $amount, string $externalReference, string $reason, ?User $operator = null): BillingPayment
    {
        PlatformOperatorGate::assert($operator);

        if (trim($reason) === '' || trim($externalReference) === '') {
            throw new DomainException('A manual payment needs its external reference and a reason.');
        }

        return TenantContext::current()->run((int) $invoice->tenant_id, fn (): BillingPayment => DB::transaction(function () use ($invoice, $amount, $externalReference, $reason, $operator): BillingPayment {
            BillingLock::tenant((int) $invoice->tenant_id);
            $subscription = BillingLock::subscription((int) $invoice->billing_subscription_id);
            /** @var BillingInvoice $locked */
            $locked = BillingInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($subscription->source === SubscriptionSource::Provider) {
                throw new DomainException('This subscription is paid through the provider; record its payments there.');
            }

            if ($locked->status !== InvoiceStatus::Open) {
                throw new DomainException('The invoice is not due.');
            }

            if ($amount->currency !== $locked->currency) {
                throw new DomainException("The invoice is in {$locked->currency}; amounts are never converted.");
            }

            if ($amount->isZero()) {
                throw new DomainException('A payment is for more than nothing.');
            }

            $payment = BillingPayment::query()->create([
                'billing_invoice_id' => $locked->id,
                'reference' => (string) Str::uuid(),
                'method' => 'manual',
                'status' => PaymentStatus::Pending,
                'currency' => $amount->currency,
                'amount_minor' => $amount->minor,
                'attempted_at' => now(),
                'external_reference' => mb_substr(trim($externalReference), 0, 120),
                'recorded_by' => $operator?->getKey(),
            ]);
            AuditLog::record($payment, 'payment_recorded_manually', null, ['invoice' => $locked->number, 'amount' => $amount->decimal(), 'currency' => $amount->currency, 'external_reference' => $payment->external_reference, 'by_user_id' => $operator?->getKey()], $reason);

            $this->applyOutcome($subscription, $locked, $payment, new ProviderPaymentState('manual-'.$payment->reference, PaymentStatus::Succeeded, $amount), 'manual');

            return $payment;
        }));
    }

    /**
     * Refunds (part of) a succeeded payment — through the provider, or recorded for a manual one.
     * Platform only. A refund never re-opens the invoice or changes the subscription: what follows
     * is a platform decision (owner decision D-S4-O6).
     */
    public function refund(BillingPayment $payment, Money $amount, string $reason, ?User $operator = null): BillingPayment
    {
        PlatformOperatorGate::assert($operator);

        if (trim($reason) === '') {
            throw new DomainException('A reason is required to refund a payment.');
        }

        $available = $payment->amount()->minus(Money::of((int) $payment->amount_refunded_minor, $payment->currency));

        if (! in_array($payment->status, [PaymentStatus::Succeeded, PaymentStatus::PartiallyRefunded], true) || $amount->isZero() || $amount->greaterThan($available)) {
            throw new DomainException("Only up to {$available->format()} of this payment can be refunded.");
        }

        $refunded = Money::of((int) $payment->amount_refunded_minor, $payment->currency)->plus($amount);
        $state = $payment->method === 'manual'
            ? new ProviderPaymentState((string) $payment->provider_payment_ref, $refunded->equals($payment->amount()) ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded, $payment->amount(), $refunded)
            : $this->providers->default()->refund((string) $payment->provider_payment_ref, $amount, 'refund-'.$payment->reference.'-'.$refunded->minor);

        return TenantContext::current()->run((int) $payment->tenant_id, fn (): BillingPayment => DB::transaction(function () use ($payment, $state, $reason, $amount): BillingPayment {
            BillingLock::tenant((int) $payment->tenant_id);
            [$subscription, $invoice, $locked] = BillingLock::payment((int) $payment->id);
            $this->applyOutcome($subscription, $invoice, $locked, $state, 'platform');
            AuditLog::record($locked, 'payment_refund_requested', null, ['amount' => $amount->decimal(), 'currency' => $amount->currency], $reason);

            return $locked;
        }));
    }

    private function succeeded(BillingSubscription $subscription, BillingInvoice $invoice, BillingPayment $payment, ProviderPaymentState $state, string $source): string
    {
        if ($payment->status->rank() >= PaymentStatus::Succeeded->rank()) {
            return 'stale';
        }

        // The provider confirms what we asked for; any other amount or currency is never settled.
        if ($state->amount !== null && ! $state->amount->equals($payment->amount())) {
            return $this->refused($payment, 'amount mismatch', $source, ['reported' => $state->amount->format(), 'expected' => $payment->amount()->format()]);
        }

        $from = $payment->status;
        $payment->forceFill(['status' => PaymentStatus::Succeeded, 'completed_at' => now(), 'failure_code' => null, 'failure_message' => null])->save();

        $paid = $invoice->amountPaid()->plus($payment->amount());
        $due = $paid->greaterThan($invoice->total()) ? Money::zero($invoice->currency) : $invoice->total()->minus($paid);
        $wasOpen = $invoice->status === InvoiceStatus::Open;

        $invoice->forceFill([
            'amount_paid_minor' => $paid->minor,
            'amount_due_minor' => $due->minor,
            ...($wasOpen && $due->isZero() ? ['status' => InvoiceStatus::Paid, 'paid_at' => now()] : []),
        ])->save();

        AuditLog::record($payment, 'payment_succeeded', ['status' => $from->value], ['status' => PaymentStatus::Succeeded->value, 'amount' => $payment->amount()->decimal(), 'currency' => $payment->currency, 'invoice' => $invoice->number, 'provider_payment_ref' => $payment->provider_payment_ref, 'source' => $source]);

        if ($paid->greaterThan($invoice->total()) || ! $wasOpen) {
            // Paid twice (two attempts both succeeded), or after a void: recorded, flagged for the
            // platform (refund policy, D-S4-O6), never silently absorbed.
            AuditLog::record($payment, 'billing_overpayment', null, ['invoice' => $invoice->number, 'paid' => $paid->format(), 'total' => $invoice->total()->format()]);
            Log::warning('billing.overpayment', ['tenant_id' => $payment->tenant_id, 'invoice_id' => $invoice->id]);
        }

        if ($wasOpen && $invoice->status === InvoiceStatus::Paid) {
            AuditLog::record($invoice, 'invoice_paid', ['status' => InvoiceStatus::Open->value], ['status' => InvoiceStatus::Paid->value, 'number' => $invoice->number]);
            $this->outcomes->invoicePaid($subscription, $invoice, $source);
        }

        return 'applied';
    }

    private function failed(BillingSubscription $subscription, BillingInvoice $invoice, BillingPayment $payment, ProviderPaymentState $state, string $source): string
    {
        if ($payment->status->rank() >= PaymentStatus::Failed->rank()) {
            return 'stale';
        }

        $payment->forceFill([
            'status' => PaymentStatus::Failed,
            'completed_at' => now(),
            'failure_code' => $state->failureCode !== null ? mb_substr(preg_replace('/[^A-Za-z0-9_.-]/', '', $state->failureCode), 0, 60) : null,
            'failure_message' => self::safeMessage($state->failureMessage),
        ])->save();

        AuditLog::record($payment, 'payment_failed', ['status' => PaymentStatus::Pending->value], ['status' => PaymentStatus::Failed->value, 'failure_code' => $payment->failure_code, 'invoice' => $invoice->number, 'source' => $source]);
        $this->outcomes->paymentFailed($subscription, $invoice, $source);

        return 'applied';
    }

    private function refunded(BillingPayment $payment, ProviderPaymentState $state, string $source): string
    {
        $refunded = $state->refunded;

        if (! in_array($payment->status, [PaymentStatus::Succeeded, PaymentStatus::PartiallyRefunded], true) || $refunded === null) {
            return 'stale';
        }

        if ($refunded->currency !== $payment->currency || $refunded->greaterThan($payment->amount())) {
            return $this->refused($payment, 'refund amount mismatch', $source);
        }

        if ($refunded->minor <= $payment->amount_refunded_minor) {
            return 'stale';
        }

        $from = $payment->status;
        $to = $refunded->equals($payment->amount()) ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded;
        $payment->forceFill(['status' => $to, 'amount_refunded_minor' => $refunded->minor])->save();
        AuditLog::record($payment, 'payment_refunded', ['status' => $from->value], ['status' => $to->value, 'refunded' => $refunded->decimal(), 'currency' => $refunded->currency, 'source' => $source]);

        return 'applied';
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function refused(BillingPayment $payment, string $why, string $source, array $details = []): string
    {
        AuditLog::record($payment, 'billing_outcome_refused', null, ['why' => $why, 'source' => $source, ...$details]);
        Log::warning('billing.outcome_refused', ['tenant_id' => $payment->tenant_id, 'payment_id' => $payment->id, 'why' => $why, 'source' => $source]);

        return $why;
    }

    /**
     * A provider's failure text for people — short, and never anything that looks like a card or
     * account number.
     */
    public static function safeMessage(?string $message): ?string
    {
        if ($message === null || trim($message) === '') {
            return null;
        }

        return mb_substr((string) preg_replace('/\d[\d\s-]{7,}\d/', '[redacted]', trim($message)), 0, 255);
    }
}
