<?php

namespace App\Console\Commands;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionSource;
use App\Models\BillingSubscription as Subscription;
use App\Models\Tenant;
use App\Services\Billing\Money;
use App\Services\Billing\PriceCatalogService;
use App\Services\Billing\Providers\ProviderUnavailable;
use App\Services\Billing\SubscriptionService;
use App\Services\Platform\Commercial\PlanAssignmentService;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * SaaS-4: platform staff subscribe a tenant, change its plan, or cancel (audited, with a reason).
 */
#[Signature('billing:subscription
    {slug : The tenant}
    {action : subscribe, change-plan, cancel (at period end) or cancel-now}
    {--plan= : Plan code (subscribe, change-plan)}
    {--interval=month}
    {--currency=INR}
    {--source=provider : provider, manual or contract}
    {--amount= : Contract only: the negotiated amount}
    {--contract= : Contract only: the contract reference}
    {--reason= : Why (required)}')]
#[Description('Subscribe a tenant, change its plan, or cancel its subscription (platform)')]
class BillingSubscription extends Command
{
    public function handle(SubscriptionService $subscriptions, PriceCatalogService $prices, PlanAssignmentService $plans): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->argument('slug'))->first();

        if ($tenant === null) {
            $this->error('No such tenant.');

            return self::FAILURE;
        }

        $reason = (string) $this->option('reason');

        try {
            $interval = BillingInterval::tryFrom((string) $this->option('interval')) ?? throw new DomainException('The interval is month or year.');
            $live = TenantContext::current()->run($tenant, fn (): ?Subscription => Subscription::query()->where('is_live', true)->first());
            $price = fn () => $prices->current($plans->latestVersion((string) $this->option('plan')), (string) $this->option('currency'), $interval) ?? throw new DomainException('That plan has no current price in this currency and interval.');

            $subscription = match ((string) $this->argument('action')) {
                'subscribe' => (string) $this->option('source') === SubscriptionSource::Contract->value
                    ? $subscriptions->subscribe($tenant, SubscriptionSource::Contract, $reason, contractPlan: $plans->latestVersion((string) $this->option('plan')), contractAmount: Money::parse((string) $this->option('amount'), (string) $this->option('currency')), contractInterval: $interval, contractReference: $this->option('contract'))
                    : $subscriptions->subscribe($tenant, SubscriptionSource::tryFrom((string) $this->option('source')) ?? throw new DomainException('Unknown source.'), $reason, price: $price()),
                'change-plan' => $subscriptions->changePlan($live ?? throw new DomainException('The tenant has no live subscription.'), $price(), $reason),
                'cancel' => $subscriptions->cancelForPlatform($live ?? throw new DomainException('The tenant has no live subscription.'), $reason),
                'cancel-now' => $subscriptions->cancelForPlatform($live ?? throw new DomainException('The tenant has no live subscription.'), $reason, immediately: true),
                default => throw new DomainException('Unknown action.'),
            };
        } catch (DomainException|InvalidArgumentException|ProviderUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$tenant->slug}: subscription #{$subscription->id} is {$subscription->status->label()}.");

        return self::SUCCESS;
    }
}
