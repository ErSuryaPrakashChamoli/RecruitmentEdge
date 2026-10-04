<?php

namespace App\Services\Billing\Providers;

use App\Services\Billing\Money;

/**
 * SaaS-4: what the domain asks a provider to collect. $reference is our payment's opaque id: the
 * provider stores it and echoes it back, so an event can be matched even before its id is recorded.
 */
final readonly class PaymentRequest
{
    public function __construct(
        public string $providerCustomerRef,
        public Money $amount,
        public string $invoiceNumber,
        public string $reference,
        public string $idempotencyKey,
    ) {}
}
