<?php

namespace Tests\Feature\Billing;

use App\Enums\BillingInterval;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionSource;
use App\Models\BillingCustomer;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingPrice;
use App\Models\BillingSubscription;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\Money;
use App\Services\Billing\PriceCatalogService;
use App\Services\Billing\Providers\BillingProviderManager;
use App\Services\Billing\Providers\FakeBillingProvider;
use App\Services\Billing\SubscriptionService;
use App\Services\Platform\Commercial\PlanAssignmentService;
use App\Services\Platform\Commercial\PlanCatalogService;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * SaaS-4 billing fixtures, beside the test's own tenant (legacy plan, no billing):
 *
 * - paying: Active, Growth v1 — about to subscribe (provider billing)
 * - trialing: Trial (14 days left), Starter v1
 * - other: Active, Growth v1 — the tenant whose billing must never be visible to the others
 *
 * Prices (test values, not an offer): Starter 1,999.00 and Growth 4,999.00 INR per month,
 * Growth 49,990.00 INR per year. Each tenant has a CHRO ("admin": billing.view and billing.manage).
 * The fake provider signs webhooks with WEBHOOK_SECRET.
 */
final class BillingWorld
{
    public const WEBHOOK_SECRET = 'whsec_test_billing';

    /** @var array<string, Tenant> */
    public array $tenants = [];

    /** @var array<string, User> */
    public array $admins = [];

    /** @var array<string, BillingPrice> */
    public array $prices = [];

    public static function build(): self
    {
        config(['billing.providers.fake.webhook_secret' => self::WEBHOOK_SECRET, 'billing.provider' => 'fake']);
        app(PlanCatalogService::class)->sync();
        $world = new self;
        $plans = app(PlanAssignmentService::class);
        $catalog = app(PriceCatalogService::class);

        $world->prices['starter'] = $catalog->publish($plans->latestVersion('starter'), Money::parse('1999.00', 'INR'), BillingInterval::Month);
        $world->prices['growth'] = $catalog->publish($plans->latestVersion('growth'), Money::parse('4999.00', 'INR'), BillingInterval::Month);
        $world->prices['growthYearly'] = $catalog->publish($plans->latestVersion('growth'), Money::parse('49990.00', 'INR'), BillingInterval::Year);

        $world->tenants['paying'] = Tenant::factory()->onPlan('growth')->create(['slug' => 'paying-co', 'name' => 'Paying Co']);
        $world->tenants['trialing'] = Tenant::factory()->trial(14)->onPlan('starter')->create(['slug' => 'trial-co', 'name' => 'Trial Co']);
        $world->tenants['other'] = Tenant::factory()->onPlan('growth')->create(['slug' => 'other-co', 'name' => 'Other Co']);

        foreach ($world->tenants as $key => $tenant) {
            $world->admins[$key] = TenantContext::current()->run($tenant, function () use ($tenant): User {
                (new RolePermissionSeeder)->run();

                return User::factory()->create(['email' => "admin@{$tenant->slug}.test", 'employee_id' => Employee::factory()->create()->id])->assignRole('chro');
            });
        }

        return $world;
    }

    public function tenant(string $key): Tenant
    {
        return $this->tenants[$key]->fresh();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function in(string $key, callable $callback): mixed
    {
        return TenantContext::current()->run($this->tenants[$key]->fresh(), $callback);
    }

    public function subscribe(string $key, string $price = 'growth', SubscriptionSource $source = SubscriptionSource::Provider): BillingSubscription
    {
        return app(SubscriptionService::class)->subscribe($this->tenant($key), $source, 'Signed up', price: $this->prices[$price]->fresh());
    }

    public function subscription(string $key): ?BillingSubscription
    {
        return $this->in($key, fn (): ?BillingSubscription => BillingSubscription::query()->latest('id')->first());
    }

    public function invoices(string $key): mixed
    {
        return $this->in($key, fn () => BillingInvoice::query()->orderBy('id')->get());
    }

    public function lastPayment(string $key): ?BillingPayment
    {
        return $this->in($key, fn (): ?BillingPayment => BillingPayment::query()->latest('id')->first());
    }

    public function customerRef(string $key): ?string
    {
        return $this->in($key, fn (): ?string => BillingCustomer::query()->value('provider_customer_ref'));
    }

    public function provider(): FakeBillingProvider
    {
        /** @var FakeBillingProvider */
        return app(BillingProviderManager::class)->find('fake');
    }

    /**
     * Posts a signed provider notification about $payment (as the provider would).
     *
     * @param  array<string, mixed>  $data  overrides of the event's data
     */
    public function webhook(string $type, BillingPayment $payment, array $data = [], ?string $eventId = null, ?int $timestamp = null, ?string $secret = null): TestResponse
    {
        $payload = [
            'id' => $eventId ?? 'evt_'.Str::random(16),
            'type' => $type,
            'created' => now()->getTimestamp(),
            'data' => [
                'payment' => $payment->provider_payment_ref,
                'reference' => $payment->reference,
                'customer' => $this->customerRefFor($payment),
                'amount' => $payment->amount()->decimal(),
                'currency' => $payment->currency,
                ...$data,
            ],
        ];

        return $this->postWebhook(json_encode($payload, JSON_THROW_ON_ERROR), $timestamp, $secret);
    }

    public function postWebhook(string $body, ?int $timestamp = null, ?string $secret = null): TestResponse
    {
        $signature = FakeBillingProvider::signature($body, $timestamp ?? now()->getTimestamp(), $secret ?? self::WEBHOOK_SECRET);

        return test()->call('POST', '/webhooks/billing/fake', [], [], [], ['HTTP_FAKE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $body);
    }

    /**
     * The provider settles the tenant's latest payment and says so.
     */
    public function pay(string $key, ?string $eventId = null): TestResponse
    {
        $payment = $this->lastPayment($key);
        $this->provider()->settle((string) $payment->provider_payment_ref, PaymentStatus::Succeeded);

        return $this->webhook('payment.succeeded', $payment, eventId: $eventId);
    }

    public function decline(string $key): TestResponse
    {
        $payment = $this->lastPayment($key);
        $this->provider()->settle((string) $payment->provider_payment_ref, PaymentStatus::Failed, 'card_declined');

        return $this->webhook('payment.failed', $payment, ['failure_code' => 'card_declined', 'failure_message' => 'Your card was declined.']);
    }

    private function customerRefFor(BillingPayment $payment): ?string
    {
        return TenantContext::current()->run((int) $payment->tenant_id, fn (): ?string => BillingCustomer::query()->value('provider_customer_ref'));
    }
}
