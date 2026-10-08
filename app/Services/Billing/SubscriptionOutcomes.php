<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\BillingInvoice;
use App\Models\BillingSubscription;

/**
 * SaaS-4: what a payment outcome means for the subscription (the dunning rules), applied through
 * the state machine. All inside the caller's transaction, with the subscription and invoice locked.
 *
 * - Paid in full, nothing else outstanding: Pending / Trialing / PastDue / Unpaid → Active (grace
 *   cleared). Active and Cancelling stay as they are (a renewal was paid).
 * - Payment failed on an invoice still due: Active / Trialing → PastDue, the grace period starting
 *   now (billing.grace_days). A further failure never extends the grace period. Pending stays
 *   Pending (it expires unpaid after the grace period).
 * - A payment for a subscription that has ended is recorded, never re-opens it (audited).
 */
class SubscriptionOutcomes
{
    public function __construct(private readonly SubscriptionStateMachine $machine) {}

    public function invoicePaid(BillingSubscription $subscription, BillingInvoice $invoice, string $source): void
    {
        if ($subscription->status->isTerminal()) {
            AuditLog::record($subscription, 'billing_payment_after_end', null, ['invoice' => $invoice->number, 'subscription_status' => $subscription->status->value]);

            return;
        }

        $outstanding = BillingInvoice::query()->where('billing_subscription_id', $subscription->id)->where('status', InvoiceStatus::Open->value)->exists();

        if ($outstanding || ! in_array($subscription->status, [SubscriptionStatus::Pending, SubscriptionStatus::Trialing, SubscriptionStatus::PastDue, SubscriptionStatus::Unpaid], true)) {
            return;
        }

        $this->machine->transition($subscription, SubscriptionStatus::Active, "Invoice {$invoice->number} paid", $source, [
            'past_due_since' => null,
            'grace_ends_at' => null,
            'activated_at' => $subscription->activated_at ?? now(),
        ]);
    }

    public function paymentFailed(BillingSubscription $subscription, BillingInvoice $invoice, string $source): void
    {
        if ($invoice->status !== InvoiceStatus::Open || ! in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing], true)) {
            return;
        }

        $this->machine->transition($subscription, SubscriptionStatus::PastDue, "Payment of invoice {$invoice->number} failed", $source, [
            'past_due_since' => now(),
            'grace_ends_at' => now()->addDays((int) config('billing.grace_days', 7)),
        ]);
    }
}
