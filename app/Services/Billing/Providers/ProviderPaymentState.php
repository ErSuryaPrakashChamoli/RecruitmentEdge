<?php

namespace App\Services\Billing\Providers;

use App\Enums\PaymentStatus;
use App\Services\Billing\Money;

/**
 * SaaS-4: a provider's statement about one payment, in canonical terms.
 */
final readonly class ProviderPaymentState
{
    public function __construct(
        public string $providerPaymentRef,
        public PaymentStatus $status,
        public ?Money $amount = null,
        public ?Money $refunded = null,
        public ?string $reference = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
    ) {}
}
