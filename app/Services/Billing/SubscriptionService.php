<?php

namespace App\Services\Billing;

use App\Enums\BillingInterval;
use App\Enums\InvoiceStatus;
use App\Enums\PlanStatus;
use App\Enums\PlanVersionStatus;
use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\AuditLog;
use App\Models\BillingInvoice;
use App\Models\BillingPrice;
use App\Models\BillingSubscription;
use App\Models\PlanVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\Providers\BillingProviderManager;
use App\Services\Billing\Providers\ProviderUnavailable;
use App\Services\Platform\Commercial\CommercialSubscriptionService;
use App\Services\Platform\Commercial\PlatformOperatorGate;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SaaS-4: the commercial subscription — created, changed and ended here, never anywhere else.
 * Creating one, changing its plan and cancelling it at once are platform operations
 * (PlatformOperatorGate); a tenant's billing administrator (billing.manage) can only cancel at the
 * end of the paid period, and resume.
 *
 * At most one live subscription per tenant, enforced under the tenant lock and by a unique index:
 * creating the same subscription again returns it; creating a different one is refused (change the
 * plan instead). The plan is a SaaS-3 plan version and stays pinned to it; the price is a snapshot.
 */
class SubscriptionService
{
    public function __construct(
        private readonly BillingCustomerService $customers,
        private readonly InvoiceService $invoices,
        private readonly PaymentService $payments,
        private readonly SubscriptionStateMachine $machine,
        private readonly CommercialSubscriptionService $commercial,
        private readonly BillingProviderManager $providers,
        private readonly BillingStatusService $status,
    ) {}

    /**
     * Subscribes $tenant to a catalog price (provider or manual billing), or — for a contract — to a
     * plan version at a negotiated amount. During a running SaaS-3 trial the subscription trials
     * until the trial ends; otherwise it awaits its first payment (a contract starts active).
     */
    public function subscribe(Tenant $tenant, SubscriptionSource $source, string $reason, ?User $operator = null, ?BillingPrice $price = null, ?PlanVersion $contractPlan = null, ?Money $contractAmount = null, ?BillingInterval $contractInterval = null, ?string $contractReference = null): BillingSubscription
    {
        PlatformOperatorGate::assert($operator);
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('A reason is required to subscribe a tenant.');
        }

        [$plan, $amount, $interval] = $source === SubscriptionSource::Contract
            ? [$contractPlan, $contractAmount, $contractInterval]
            : [$price?->planVersion, $price?->money(), $price?->interval];

        if ($plan === null || $amount === null || $interval === null) {
            throw new DomainException($source === SubscriptionSource::Contract ? 'A contract needs its plan, amount and interval.' : 'A subscription needs a price from the catalog.');
        }

        if ($source === SubscriptionSource::Contract && blank($contractReference)) {
            throw new DomainException('A contract subscription needs the contract reference.');
        }

        if ($price !== null && ! $price->is_current) {
            throw new DomainException('That price has been replaced; use the current price.');
        }

        $plan->loadMissing('plan');

        if ($plan->status !== PlanVersionStatus::Published || $plan->plan->status !== PlanStatus::Active) {
            throw new DomainException("{$plan->label()} cannot be purchased.");
        }

        $provider = $source === SubscriptionSource::Provider ? $this->providers->default() : null;

        [$subscription, $invoice] = TenantContext::current()->run($tenant, function () use ($tenant, $source, $reason, $operator, $price, $plan, $amount, $interval, $contractReference, $provider): array {
            $customer = $this->customers->forCurrentTenant();

            if ($provider !== null) {
                $customer = $this->customers->ensureProviderCustomer($customer, $provider);
            }

            return DB::transaction(function () use ($tenant, $customer, $source, $reason, $operator, $price, $plan, $amount, $interval, $contractReference, $provider): array {
                $locked = BillingLock::tenant((int) $tenant->getKey());

                if (in_array($locked->status, [TenantStatus::Provisioning, TenantStatus::Cancelled, TenantStatus::DeletionPending, TenantStatus::Deleted], true)) {
                    throw new DomainException("A {$locked->status->label()} tenant cannot be subscribed.");
                }

                /** @var BillingSubscription|null $live */
                $live = BillingSubscription::query()->where('is_live', true)->lockForUpdate()->first();

                if ($live !== null) {
                    $same = $live->source === $source && (int) $live->plan_version_id === (int) $plan->id && $live->money()->equals($amount) && $live->interval === $interval;

                    return $same ? [$live, null] : throw new DomainException("The tenant already has subscription #{$live->id} ({$live->status->label()}): change its plan instead.");
                }

                $trialing = $locked->status === TenantStatus::Trial && ! $locked->trialHasExpired();
                $start = ($trialing ? $locked->trial_ends_at : now())->copy()->startOfSecond();
                $status = match (true) {
                    $source === SubscriptionSource::Contract => SubscriptionStatus::Active,
                    $trialing => SubscriptionStatus::Trialing,
                    default => SubscriptionStatus::Pending,
                };

                $subscription = BillingSubscription::query()->create([
                    'billing_customer_id' => $customer->id,
                    'plan_version_id' => $plan->id,
                    'billing_price_id' => $price?->id,
                    'source' => $source,
                    'provider' => $provider?->name(),
                    'status' => $status,
                    'is_live' => true,
                    'currency' => $amount->currency,
                    'interval' => $interval,
                    'amount_minor' => $amount->minor,
                    'activated_at' => $status === SubscriptionStatus::Pending ? null : now(),
                    'current_period_start' => $start,
                    'current_period_end' => $interval->after($start),
                    'trial_start' => $trialing ? $locked->trial_started_at : null,
                    'trial_end' => $trialing ? $locked->trial_ends_at : null,
                    'contract_reference' => $contractReference !== null ? mb_substr(trim($contractReference), 0, 120) : null,
                    'created_by' => $operator?->getKey(),
                ]);

                AuditLog::record($subscription, 'subscription_created', null, ['status' => $status->value, 'source' => $source->value, 'plan_version_id' => $plan->id, 'amount' => $amount->decimal(), 'currency' => $amount->currency, 'interval' => $interval->value, 'contract_reference' => $subscription->contract_reference, 'by_user_id' => $operator?->getKey()], $reason);
                Log::notice('platform.billing_subscription_created', ['tenant_id' => $tenant->getKey(), 'subscription_id' => $subscription->id, 'status' => $status->value]);

                // Billed in advance: the first period's invoice now (a trial's at its end).
                $invoice = $trialing ? null : $this->invoices->issue($subscription, $start, $subscription->current_period_end);

                if ($invoice !== null && $invoice->status === InvoiceStatus::Paid && $status === SubscriptionStatus::Pending) {
                    $this->machine->transition($subscription, SubscriptionStatus::Active, 'Nothing to pay', 'billing', ['activated_at' => now()]);
                }

                $this->status->touch((int) $tenant->getKey());
                $this->commercial->sync($subscription);

                return [$subscription, $invoice];
            });
        });

        if ($invoice !== null && $subscription->source === SubscriptionSource::Provider) {
            $this->collectSafely($tenant, $invoice);
        }

        return $subscription->fresh();
    }

    /**
     * Moves a live subscription to another plan (price) — platform only. Effective at once for what
     * the tenant may use (SaaS-3 plan assignment); the new amount is billed from the next period:
     * the current period's invoice is never changed and nothing is prorated (D-S4-O5). Downgrades
     * keep every record (SaaS-3).
     */
    public function changePlan(BillingSubscription $subscription, BillingPrice $price, string $reason, ?User $operator = null): BillingSubscription
    {
        PlatformOperatorGate::assert($operator);

        if (trim($reason) === '' || ! $price->is_current) {
            throw new DomainException('A plan change needs a reason and a current price.');
        }

        $price->loadMissing('planVersion.plan');

        if ($price->planVersion->status !== PlanVersionStatus::Published || $price->planVersion->plan->status !== PlanStatus::Active) {
            throw new DomainException("{$price->planVersion->label()} cannot be purchased.");
        }

        return TenantContext::current()->run((int) $subscription->tenant_id, fn (): BillingSubscription => DB::transaction(function () use ($subscription, $price, $reason): BillingSubscription {
            BillingLock::tenant((int) $subscription->tenant_id);
            $locked = BillingLock::subscription((int) $subscription->id);

            if (! $locked->status->grantsPlan() && $locked->status !== SubscriptionStatus::Unpaid) {
                throw new DomainException("A {$locked->status->label()} subscription's plan cannot be changed.");
            }

            if ($price->currency !== $locked->currency || $price->interval !== $locked->interval) {
                throw new DomainException("The new price must be in {$locked->currency}, {$locked->interval->label()}: currency and interval do not change mid-subscription.");
            }

            if ((int) $locked->billing_price_id === (int) $price->id) {
                return $locked;
            }

            $old = ['plan_version_id' => $locked->plan_version_id, 'billing_price_id' => $locked->billing_price_id, 'amount' => $locked->money()->decimal()];
            $locked->forceFill(['plan_version_id' => $price->plan_version_id, 'billing_price_id' => $price->id, 'amount_minor' => $price->amount_minor])->save();
            AuditLog::record($locked, 'subscription_plan_changed', $old, ['plan_version_id' => $price->plan_version_id, 'billing_price_id' => $price->id, 'amount' => $price->money()->decimal()], $reason);
            $this->machine->resync($locked);

            return $locked;
        }));
    }

    /**
     * A tenant's billing administrator (billing.manage) ends the subscription at the end of the paid
     * period (a trial: at its end). Nothing is deleted; access ends with the period.
     */
    public function cancelAtPeriodEnd(BillingSubscription $subscription, string $reason, User $actor): BillingSubscription
    {
        $this->assertTenantAction($subscription, $actor);

        return $this->onLive($subscription, fn (BillingSubscription $locked): BillingSubscription => $this->endAtPeriodEnd($locked, $reason, 'tenant'));
    }

    /**
     * Platform: ends the subscription at period end, or at once (cancelled now, access suspended).
     */
    public function cancelForPlatform(BillingSubscription $subscription, string $reason, ?User $operator = null, bool $immediately = false): BillingSubscription
    {
        PlatformOperatorGate::assert($operator);

        if (trim($reason) === '') {
            throw new DomainException('A reason is required to cancel a subscription.');
        }

        return $this->onLive($subscription, fn (BillingSubscription $locked): BillingSubscription => $immediately
            ? $this->machine->transition($locked, SubscriptionStatus::Cancelled, $reason, 'platform', ['cancelled_at' => now(), 'cancel_at' => now()])
            : $this->endAtPeriodEnd($locked, $reason, 'platform'));
    }

    /**
     * Withdraws a cancellation at period end (before the period ends) — billing.manage.
     */
    public function resume(BillingSubscription $subscription, User $actor): BillingSubscription
    {
        $this->assertTenantAction($subscription, $actor);

        return $this->onLive($subscription, function (BillingSubscription $locked): BillingSubscription {
            if ($locked->status !== SubscriptionStatus::Cancelling) {
                throw new DomainException('Only a subscription ending at period end can be resumed.');
            }

            $trialing = $locked->trial_end !== null && $locked->trial_end->isFuture() && BillingInvoice::query()->where('billing_subscription_id', $locked->id)->doesntExist();

            return $this->machine->transition($locked, $trialing ? SubscriptionStatus::Trialing : SubscriptionStatus::Active, 'Cancellation withdrawn', 'tenant', ['cancel_at' => null, 'cancelled_at' => null]);
        });
    }

    private function endAtPeriodEnd(BillingSubscription $locked, string $reason, string $source): BillingSubscription
    {
        if (! in_array($locked->status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing], true)) {
            throw new DomainException("A {$locked->status->label()} subscription cannot be cancelled at period end.");
        }

        $endsAt = $locked->status === SubscriptionStatus::Trialing ? $locked->current_period_start : $locked->current_period_end;

        return $this->machine->transition($locked, SubscriptionStatus::Cancelling, trim($reason) !== '' ? $reason : 'Cancelled at period end', $source, ['cancel_at' => $endsAt, 'cancelled_at' => now()]);
    }

    /**
     * A tenant action: the person holds billing.manage, in the subscription's own tenant.
     */
    private function assertTenantAction(BillingSubscription $subscription, User $actor): void
    {
        if (TenantContext::current()->id() !== (int) $subscription->tenant_id) {
            throw new DomainException('This subscription belongs to another organisation.');
        }

        BillingAuthorization::assertCanManage($actor);
    }

    /**
     * Collects an open invoice; an unreachable provider is retried by the billing clock.
     */
    public function collectSafely(Tenant|int $tenant, BillingInvoice $invoice): void
    {
        try {
            TenantContext::current()->run($tenant, fn () => $this->payments->collect($invoice));
        } catch (ProviderUnavailable|DomainException $e) {
            Log::warning('billing.collection_deferred', ['tenant_id' => $invoice->tenant_id, 'invoice_id' => $invoice->id, 'exception' => $e::class]);
        }
    }

    /**
     * @param  callable(BillingSubscription): BillingSubscription  $change
     */
    private function onLive(BillingSubscription $subscription, callable $change): BillingSubscription
    {
        return TenantContext::current()->run((int) $subscription->tenant_id, fn (): BillingSubscription => DB::transaction(function () use ($subscription, $change): BillingSubscription {
            BillingLock::tenant((int) $subscription->tenant_id);
            $locked = BillingLock::subscription((int) $subscription->id);

            if ($locked->status->isTerminal()) {
                throw new DomainException('The subscription has ended.');
            }

            return $change($locked);
        }));
    }
}
