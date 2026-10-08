<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\BillingInvoice;
use App\Models\BillingSubscription;
use App\Models\User;
use App\Services\Platform\Commercial\PlatformOperatorGate;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * SaaS-4: issues the invoice for one billing period of a subscription (in advance, at the period's
 * start) from the subscription's price snapshot — the amount it was bought or changed at, never
 * today's catalog price. Numbered from the platform's single series at issue; immutable after.
 * Tax: none is calculated (owner decision D-S4-O7); the structure (tax_minor, tax_details) is there.
 */
class InvoiceService
{
    public function __construct(
        private readonly InvoiceNumberer $numbers,
        private readonly BillingStatusService $status,
    ) {}

    /**
     * Inside the caller's transaction, with $subscription locked. One invoice per period: issuing
     * the same period again returns it.
     */
    public function issue(BillingSubscription $subscription, CarbonInterface $periodStart, CarbonInterface $periodEnd): BillingInvoice
    {
        $periodStart = $periodStart->copy()->startOfSecond();

        /** @var BillingInvoice|null $existing */
        $existing = BillingInvoice::query()->where('billing_subscription_id', $subscription->id)->where('period_start', $periodStart)->first();

        if ($existing !== null) {
            return $existing;
        }

        $subscription->loadMissing('planVersion.plan');
        $amount = $subscription->money();
        $free = $amount->isZero();

        $invoice = BillingInvoice::query()->create([
            'billing_subscription_id' => $subscription->id,
            'number' => $this->numbers->next(),
            'status' => $free ? InvoiceStatus::Paid : InvoiceStatus::Open,
            'currency' => $amount->currency,
            'subtotal_minor' => $amount->minor,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => $amount->minor,
            'amount_paid_minor' => 0,
            'amount_due_minor' => $amount->minor,
            'period_start' => $periodStart,
            'period_end' => $periodEnd->copy()->startOfSecond(),
            'description' => mb_substr("{$subscription->planVersion->label()} — {$subscription->interval->label()}, {$periodStart->toFormattedDateString()} to {$periodEnd->toFormattedDateString()}", 0, 255),
            'plan_version_id' => $subscription->plan_version_id,
            'billing_price_id' => $subscription->billing_price_id,
            'issued_at' => now(),
            'due_at' => now(),
            'paid_at' => $free ? now() : null,
        ]);

        AuditLog::record($invoice, 'invoice_issued', null, ['number' => $invoice->number, 'total' => $amount->decimal(), 'currency' => $amount->currency, 'period_start' => $periodStart->toIso8601String(), 'subscription_id' => $subscription->id]);
        $this->status->touch((int) $subscription->tenant_id);

        return $invoice;
    }

    /**
     * Withdraws an issued invoice nobody has paid anything towards. Platform only; the number stays
     * used (the series has no gaps, voided numbers included).
     */
    public function void(BillingInvoice $invoice, string $reason, ?User $operator = null): BillingInvoice
    {
        PlatformOperatorGate::assert($operator);
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('A reason is required to void an invoice.');
        }

        return TenantContext::current()->run((int) $invoice->tenant_id, fn (): BillingInvoice => DB::transaction(function () use ($invoice, $reason): BillingInvoice {
            BillingLock::tenant((int) $invoice->tenant_id);
            BillingLock::subscription((int) $invoice->billing_subscription_id);
            /** @var BillingInvoice $locked */
            $locked = BillingInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== InvoiceStatus::Open || $locked->amount_paid_minor > 0 || $locked->payments()->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Succeeded->value])->exists()) {
                throw new DomainException('Only a due invoice with no payment towards it can be voided.');
            }

            $locked->forceFill(['status' => InvoiceStatus::Void, 'amount_due_minor' => 0, 'voided_at' => now(), 'void_reason' => mb_substr($reason, 0, 255)])->save();
            AuditLog::record($locked, 'invoice_voided', ['status' => InvoiceStatus::Open->value], ['status' => InvoiceStatus::Void->value, 'number' => $locked->number], $reason);
            $this->status->touch((int) $locked->tenant_id);

            return $locked;
        }));
    }
}
