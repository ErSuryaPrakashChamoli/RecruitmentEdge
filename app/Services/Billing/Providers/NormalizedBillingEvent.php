<?php

namespace App\Services\Billing\Providers;

use App\Enums\BillingEventType;
use Carbon\CarbonImmutable;

/**
 * SaaS-4: a verified provider notification in canonical terms — the only shape the billing domain
 * processes. It names the provider's own references; the tenant, plan and invoice are always
 * found from those in local records, never read from the payload.
 */
final readonly class NormalizedBillingEvent
{
    public function __construct(
        public string $eventId,
        public string $providerType,
        public BillingEventType $type,
        public ?CarbonImmutable $occurredAt,
        public ?ProviderPaymentState $payment = null,
        public ?string $providerCustomerRef = null,
    ) {}
}
