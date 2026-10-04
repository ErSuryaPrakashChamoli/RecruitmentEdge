<?php

namespace App\Services\Billing;

use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingSubscription;
use App\Models\Tenant;

/**
 * SaaS-4: billing's lock order, inside one transaction — the tenant row (the SaaS-3 commercial
 * lock, so billing, plan, override and lifecycle changes are ordered), then the subscription, its
 * invoice, the payment, and last the invoice series. Every billing writer locks this way, so two
 * concurrent operations on one tenant's billing serialise and never deadlock.
 */
final class BillingLock
{
    public static function tenant(int $tenantId): Tenant
    {
        /** @var Tenant */
        return Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
    }

    public static function subscription(int $id): BillingSubscription
    {
        /** @var BillingSubscription */
        return BillingSubscription::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Subscription, invoice and payment of $paymentId, locked in order (after the tenant).
     *
     * @return array{0: BillingSubscription, 1: BillingInvoice, 2: BillingPayment}
     */
    public static function payment(int $paymentId): array
    {
        $invoiceId = (int) BillingPayment::query()->whereKey($paymentId)->value('billing_invoice_id');
        $subscriptionId = (int) BillingInvoice::query()->whereKey($invoiceId)->value('billing_subscription_id');

        return [
            self::subscription($subscriptionId),
            BillingInvoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail(),
            BillingPayment::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail(),
        ];
    }
}
