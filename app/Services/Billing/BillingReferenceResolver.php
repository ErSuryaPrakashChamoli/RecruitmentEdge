<?php

namespace App\Services\Billing;

use App\Models\BillingPayment;
use Illuminate\Support\Str;

/**
 * SaaS-4 (reviewed tenant crossing): finds which tenant's payment a verified provider event names —
 * by the provider's own payment id, or by our payment reference the provider echoes back — so the
 * event can then be processed inside that tenant only. A tenant id, plan or amount in a payload is
 * never used to find anything.
 */
class BillingReferenceResolver
{
    /**
     * @return array{tenant_id: int, payment_id: int}|null
     */
    public function payment(string $provider, ?string $providerPaymentRef, ?string $reference): ?array
    {
        $row = null;

        if ($providerPaymentRef !== null && $providerPaymentRef !== '') {
            $row = BillingPayment::query()->withoutTenancy()->where('provider', $provider)->where('provider_payment_ref', $providerPaymentRef)->first(['id', 'tenant_id']);
        }

        if ($row === null && $reference !== null && Str::isUuid($reference)) {
            $row = BillingPayment::query()->withoutTenancy()->where('provider', $provider)->where('reference', $reference)->first(['id', 'tenant_id']);
        }

        return $row === null ? null : ['tenant_id' => (int) $row->tenant_id, 'payment_id' => (int) $row->id];
    }
}
