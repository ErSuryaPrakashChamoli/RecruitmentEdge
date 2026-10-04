<?php

namespace App\Services\Billing;

use App\Enums\BillingInterval;
use App\Enums\PlanStatus;
use App\Enums\PlanVersionStatus;
use App\Models\AuditLog;
use App\Models\BillingPrice;
use App\Models\PlanVersion;
use App\Models\User;
use App\Services\Platform\Commercial\PlatformOperatorGate;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SaaS-4: publishes what a SaaS-3 plan version costs. A price is never edited: publishing a new
 * amount retires the current price (kept, with its end) and starts the new one — subscriptions keep
 * the snapshot they bought, and an invoice never uses a later price. Platform only; audited in the
 * platform stream. Production prices are an owner decision: nothing here seeds any.
 */
class PriceCatalogService
{
    public function publish(PlanVersion $version, Money $amount, BillingInterval $interval, ?User $operator = null): BillingPrice
    {
        PlatformOperatorGate::assert($operator);
        $version->loadMissing('plan');

        if ($version->status !== PlanVersionStatus::Published || $version->plan->status !== PlanStatus::Active) {
            throw new DomainException("{$version->label()} is not offered for sale.");
        }

        return TenantContext::current()->runWithoutTenant(fn (): BillingPrice => DB::transaction(function () use ($version, $amount, $interval, $operator): BillingPrice {
            /** @var BillingPrice|null $current */
            $current = BillingPrice::query()->where('plan_version_id', $version->id)->where('currency', $amount->currency)->where('interval', $interval->value)->where('is_current', true)->lockForUpdate()->first();

            if ($current !== null && $current->money()->equals($amount)) {
                return $current;
            }

            $current?->forceFill(['is_current' => null, 'status' => 'retired', 'effective_until' => now()])->save();

            $price = BillingPrice::query()->create([
                'plan_version_id' => $version->id,
                'currency' => $amount->currency,
                'interval' => $interval,
                'amount_minor' => $amount->minor,
                'status' => 'active',
                'is_current' => true,
                'effective_from' => now(),
                'created_by' => $operator?->getKey(),
            ]);

            AuditLog::record($price, 'billing_price_published', $current === null ? null : ['amount' => $current->money()->decimal()], ['plan' => $version->plan->code, 'version' => $version->version, 'currency' => $amount->currency, 'interval' => $interval->value, 'amount' => $amount->decimal(), 'by_user_id' => $operator?->getKey()]);
            Log::notice('platform.billing_price_published', ['plan_version_id' => $version->id, 'currency' => $amount->currency, 'interval' => $interval->value]);

            return $price;
        }));
    }

    public function current(PlanVersion $version, string $currency, BillingInterval $interval): ?BillingPrice
    {
        return BillingPrice::query()->where('plan_version_id', $version->id)->where('currency', strtoupper($currency))->where('interval', $interval->value)->where('is_current', true)->first();
    }
}
