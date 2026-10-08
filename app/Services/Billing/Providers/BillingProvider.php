<?php

namespace App\Services\Billing\Providers;

use App\Models\BillingCustomer;
use App\Services\Billing\Money;
use Illuminate\Http\Request;

/**
 * SaaS-4: the only way the billing domain talks to a payment provider. Everything provider-specific
 * (API calls, signatures, payload shapes, status vocabulary) stays behind an adapter; the domain
 * sees normalised values only. The application is the system of record for subscriptions and
 * invoices; the provider collects and refunds payments and reports their outcome.
 *
 * Every call that moves money carries an idempotency key, so a retry never charges twice. A
 * provider that cannot be reached throws ProviderUnavailable: callers keep the last known state.
 */
interface BillingProvider
{
    public function name(): string;

    /**
     * The provider's customer for $customer (created once per idempotency key).
     *
     * @throws ProviderUnavailable
     */
    public function createCustomer(BillingCustomer $customer, string $idempotencyKey): string;

    /**
     * Starts collecting a payment. The outcome may be known at once or arrive later as an event.
     *
     * @throws ProviderUnavailable
     */
    public function collect(PaymentRequest $request): ProviderPaymentState;

    /**
     * @throws ProviderUnavailable
     */
    public function refund(string $providerPaymentRef, Money $amount, string $idempotencyKey): ProviderPaymentState;

    /**
     * The provider's current view of a payment, by its id or our reference; null when unknown.
     *
     * @throws ProviderUnavailable
     */
    public function fetchPayment(?string $providerPaymentRef, string $reference): ?ProviderPaymentState;

    /**
     * Whether the request is an authentic, fresh notification from this provider (signature over the
     * raw body, timestamp within the tolerance). Nothing in an unverified request is read.
     */
    public function verifyWebhook(Request $request): bool;

    /**
     * The verified notification, normalised.
     *
     * @return array<string, mixed> the payload to store
     */
    public function webhookPayload(Request $request): array;

    /**
     * A stored payload, normalised (processing and reprocessing read only this).
     */
    public function normalize(array $payload): NormalizedBillingEvent;

    /**
     * Where the customer manages their payment method with the provider, if it offers that page.
     */
    public function paymentMethodUrl(string $providerCustomerRef): ?string;
}
