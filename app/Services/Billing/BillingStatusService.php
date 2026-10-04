<?php

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\BillingCustomer;
use App\Models\BillingSubscription;
use App\Services\Tenancy\TenantCache;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * SaaS-4: the current tenant's billing at a glance (subscription, plan, status, period, notices),
 * for its own billing page and notices. Cached per tenant and billing state version —
 * t:{tenant}:billing:status:v{n} — and the version is bumped in the same transaction as every
 * billing change (touch()), so an old entry is never read again. Never calls the provider.
 */
class BillingStatusService
{
    private const CACHE_SECONDS = 600;

    /**
     * @var array<int, array<string, mixed>|null>
     */
    private array $memo = [];

    /**
     * @return array{subscription_id: int|null, status: string|null, status_label: string|null, plan: string|null, amount: string|null, interval: string|null, period_start: string|null, period_end: string|null, cancel_at: string|null, grace_ends_at: string|null, source: string|null}
     */
    public function summary(): array
    {
        $tenantId = TenantContext::current()->requireId();

        if (isset($this->memo[$tenantId])) {
            return $this->memo[$tenantId];
        }

        $version = BillingCustomer::query()->value('state_version');

        return $this->memo[$tenantId] = Cache::remember(
            TenantCache::key('billing:status:v'.($version ?? 0), $tenantId),
            self::CACHE_SECONDS,
            fn (): array => $this->load(),
        );
    }

    /**
     * Bumps the tenant's billing state version (call inside the change's transaction).
     */
    public function touch(int $tenantId): void
    {
        BillingCustomer::query()->where('tenant_id', $tenantId)->increment('state_version');
        unset($this->memo[$tenantId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        /** @var BillingSubscription|null $subscription */
        $subscription = BillingSubscription::query()->where('is_live', true)->with('planVersion.plan')->first()
            ?? BillingSubscription::query()->with('planVersion.plan')->latest('id')->first();

        return [
            'subscription_id' => $subscription?->id,
            'status' => $subscription?->status->value,
            'status_label' => $subscription?->status->label(),
            'plan' => $subscription?->planVersion?->label(),
            'amount' => $subscription?->money()->format(),
            'interval' => $subscription?->interval->label(),
            'period_start' => $subscription?->current_period_start?->toFormattedDateString(),
            'period_end' => $subscription?->current_period_end?->toFormattedDateString(),
            'cancel_at' => $subscription?->status === SubscriptionStatus::Cancelling ? $subscription->cancel_at?->toFormattedDateString() : null,
            'grace_ends_at' => $subscription?->status === SubscriptionStatus::PastDue ? $subscription->grace_ends_at?->toFormattedDateString() : null,
            'source' => $subscription?->source->value,
        ];
    }
}
