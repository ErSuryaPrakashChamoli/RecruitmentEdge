<?php

use App\Enums\BillingEventStatus;
use App\Enums\BillingInterval;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Models\BillingCustomer;
use App\Models\BillingEvent;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingPrice;
use App\Models\BillingSubscription;
use App\Models\Tenant;
use App\Models\TenantPlanAssignment;
use App\Services\Billing\BillingClock;
use App\Services\Billing\BillingEventProcessor;
use App\Services\Billing\BillingReconciler;
use App\Services\Billing\Money;
use App\Services\Billing\PaymentService;
use App\Services\Billing\PriceCatalogService;
use App\Services\Billing\Providers\BillingProviderManager;
use App\Services\Billing\Providers\FakeBillingProvider;
use App\Services\Billing\SubscriptionService;
use App\Services\Platform\Commercial\PlanAssignmentService;
use App\Services\Platform\Commercial\PlanCatalogService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concurrency\Race;

/*
 * SaaS-4: billing under real two-transaction concurrency (MySQL 8.4, REPEATABLE READ). Every billing
 * writer takes the tenant row lock first (then subscription, invoice, payment, invoice series), so
 * the contender waits and decides on the committed outcome: duplicates apply once, stale outcomes
 * are ignored, and invoice numbers stay unique and consecutive. vendor/bin/pest -c phpunit.concurrency.xml
 */
beforeEach(function (): void {
    Race::prepareDatabase();
    Storage::fake('local');
    Mail::fake();
    config(['billing.provider' => 'fake', 'billing.providers.fake.webhook_secret' => 'whsec_race']);
    app(PlanCatalogService::class)->sync();

    $plans = app(PlanAssignmentService::class);
    $this->growth = app(PriceCatalogService::class)->publish($plans->latestVersion('growth'), Money::parse('4999.00', 'INR'), BillingInterval::Month);
    $this->starter = app(PriceCatalogService::class)->publish($plans->latestVersion('starter'), Money::parse('1999.00', 'INR'), BillingInterval::Month);
    $this->tenant = Tenant::factory()->onPlan('growth')->create(['slug' => 'bill-'.Str::lower(Str::random(10))]);
});

afterEach(function (): void {
    TenantContext::current()->setTenant(Tenant::query()->where('slug', 'concurrency')->firstOrFail());
});

function billingRaceSubscribe(Tenant $tenant, BillingPrice $price): BillingSubscription
{
    return app(SubscriptionService::class)->subscribe(Tenant::query()->findOrFail($tenant->id), SubscriptionSource::Provider, 'Signed up', price: BillingPrice::query()->findOrFail($price->id));
}

function billingRaceIn(Tenant $tenant, Closure $callback): mixed
{
    return TenantContext::current()->run(Tenant::query()->findOrFail($tenant->id), $callback);
}

function billingRacePayment(Tenant $tenant): BillingPayment
{
    return billingRaceIn($tenant, fn (): BillingPayment => BillingPayment::query()->latest('id')->firstOrFail());
}

/**
 * A stored, unprocessed provider event about $payment (what the ingestor writes before queuing).
 */
function billingRaceEvent(BillingPayment $payment, string $type, array $data = []): int
{
    $customer = TenantContext::current()->run((int) $payment->tenant_id, fn () => BillingCustomer::query()->value('provider_customer_ref'));
    $payload = ['id' => 'evt_'.Str::random(16), 'type' => $type, 'created' => now()->getTimestamp(), 'data' => ['payment' => $payment->provider_payment_ref, 'reference' => $payment->reference, 'customer' => $customer, 'amount' => $payment->amount()->decimal(), 'currency' => $payment->currency, ...$data]];

    return (int) TenantContext::current()->runWithoutTenant(fn () => BillingEvent::query()->create(['provider' => 'fake', 'provider_event_id' => $payload['id'], 'type' => $type, 'normalized_type' => app(BillingProviderManager::class)->find('fake')->normalize($payload)->type, 'occurred_at' => now(), 'received_at' => now(), 'payload_hash' => hash('sha256', json_encode($payload)), 'payload' => $payload, 'status' => BillingEventStatus::Received])->id);
}

function billingRaceProcess(int $eventId): string
{
    return app(BillingEventProcessor::class)->process($eventId);
}

function billingRaceSettle(BillingPayment $payment, PaymentStatus $status): void
{
    /** @var FakeBillingProvider $fake */
    $fake = app(BillingProviderManager::class)->find('fake');
    $fake->settle((string) $payment->provider_payment_ref, $status);
}

test('two creations of the same subscription: one subscription, one invoice', function (): void {
    $race = Race::run(
        holder: fn () => billingRaceSubscribe($this->tenant, $this->growth),
        contender: fn () => billingRaceSubscribe($this->tenant, $this->growth),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['completed'])->toBeTrue()
        ->and(billingRaceIn($this->tenant, fn () => [BillingSubscription::query()->count(), BillingInvoice::query()->count()]))->toBe([1, 1]);
});

test('the same provider event processed twice at once is applied once', function (): void {
    billingRaceSubscribe($this->tenant, $this->growth);
    $payment = billingRacePayment($this->tenant);
    billingRaceSettle($payment, PaymentStatus::Succeeded);
    $event = billingRaceEvent($payment, 'payment.succeeded');

    $race = Race::run(holder: fn () => billingRaceProcess($event), contender: fn () => billingRaceProcess($event));

    expect($race['blocked'])->toBeTrue()
        ->and($race['completed'])->toBeTrue()
        ->and(billingRaceIn($this->tenant, fn () => BillingInvoice::query()->sole()->amount_paid_minor))->toBe(499900)
        ->and(BillingEvent::query()->find($event)->status)->toBe(BillingEventStatus::Processed);
});

test('two success events for one payment at once: the invoice is paid once', function (): void {
    billingRaceSubscribe($this->tenant, $this->growth);
    $payment = billingRacePayment($this->tenant);
    $first = billingRaceEvent($payment, 'payment.succeeded');
    $second = billingRaceEvent($payment, 'payment.succeeded');

    $race = Race::run(holder: fn () => billingRaceProcess($first), contender: fn () => billingRaceProcess($second));

    expect($race['blocked'])->toBeTrue()
        ->and(billingRaceIn($this->tenant, fn () => BillingInvoice::query()->sole()->amount_paid_minor))->toBe(499900)
        ->and(BillingEvent::query()->find($second)->note)->toBe('stale');
});

test('a payment that lands while the subscription is being cancelled is recorded and never re-opens it', function (): void {
    $subscription = billingRaceSubscribe($this->tenant, $this->growth);
    $event = billingRaceEvent(billingRacePayment($this->tenant), 'payment.succeeded');

    $race = Race::run(
        holder: fn () => app(SubscriptionService::class)->cancelForPlatform($subscription, 'Customer left', immediately: true),
        contender: fn () => billingRaceProcess($event),
    );

    expect($race['blocked'])->toBeTrue()
        ->and(billingRaceIn($this->tenant, fn () => BillingSubscription::query()->sole()->status))->toBe(SubscriptionStatus::Cancelled)
        ->and(billingRacePayment($this->tenant)->status)->toBe(PaymentStatus::Succeeded);
});

test('success and a late failure for one payment at once: success stands', function (): void {
    billingRaceSubscribe($this->tenant, $this->growth);
    $payment = billingRacePayment($this->tenant);
    $success = billingRaceEvent($payment, 'payment.succeeded');
    $failure = billingRaceEvent($payment, 'payment.failed', ['failure_code' => 'late']);

    $race = Race::run(holder: fn () => billingRaceProcess($success), contender: fn () => billingRaceProcess($failure));

    expect($race['blocked'])->toBeTrue()
        ->and(billingRacePayment($this->tenant)->status)->toBe(PaymentStatus::Succeeded)
        ->and(billingRaceIn($this->tenant, fn () => BillingSubscription::query()->sole()->status))->toBe(SubscriptionStatus::Active)
        ->and(BillingEvent::query()->find($failure)->note)->toBe('stale');
});

test('a plan change committed while a renewal waits is billed in that renewal', function (): void {
    $subscription = billingRaceSubscribe($this->tenant, $this->starter);
    billingRaceProcess(billingRaceEvent(billingRacePayment($this->tenant), 'payment.succeeded'));
    billingRaceIn($this->tenant, fn () => DB::table('billing_subscriptions')->where('tenant_id', $this->tenant->id)->update(['current_period_end' => now()->subMinute()]));

    $race = Race::run(
        holder: fn () => app(SubscriptionService::class)->changePlan(billingRaceIn($this->tenant, fn () => BillingSubscription::query()->sole()), BillingPrice::query()->findOrFail($this->growth->id), 'Upgrade'),
        contender: fn () => billingRaceIn($this->tenant, fn () => app(BillingClock::class)->tick()),
    );

    expect($race['blocked'])->toBeTrue()
        ->and(billingRaceIn($this->tenant, fn () => BillingInvoice::query()->orderByDesc('id')->first()->total_minor))->toBe(499900)
        ->and(billingRaceIn($this->tenant, fn () => BillingInvoice::query()->count()))->toBe(2);
});

test('a plan change committed while a payment is processed: the paid subscription carries the new plan', function (): void {
    $subscription = billingRaceSubscribe($this->tenant, $this->starter);
    $event = billingRaceEvent(billingRacePayment($this->tenant), 'payment.succeeded');
    billingRaceIn($this->tenant, fn () => DB::table('billing_subscriptions')->where('tenant_id', $this->tenant->id)->update(['status' => 'active', 'activated_at' => now()]));

    $race = Race::run(
        holder: fn () => app(SubscriptionService::class)->changePlan(billingRaceIn($this->tenant, fn () => BillingSubscription::query()->sole()), BillingPrice::query()->findOrFail($this->growth->id), 'Upgrade'),
        contender: fn () => billingRaceProcess($event),
    );

    expect($race['blocked'])->toBeTrue()
        ->and(billingRaceIn($this->tenant, fn () => TenantPlanAssignment::query()->where('is_current', true)->sole()->plan_version_id))->toBe($this->growth->plan_version_id)
        ->and(billingRacePayment($this->tenant)->status)->toBe(PaymentStatus::Succeeded);
});

test('a refund and the provider\'s notice of it at once: refunded once', function (): void {
    billingRaceSubscribe($this->tenant, $this->growth);
    $payment = billingRacePayment($this->tenant);
    billingRaceSettle($payment, PaymentStatus::Succeeded);
    billingRaceProcess(billingRaceEvent($payment, 'payment.succeeded'));
    $notice = billingRaceEvent($payment, 'payment.refunded', ['refunded' => '999.00']);

    $race = Race::run(
        holder: fn () => app(PaymentService::class)->refund(billingRacePayment($this->tenant), Money::parse('999.00', 'INR'), 'Goodwill'),
        contender: fn () => billingRaceProcess($notice),
    );

    expect($race['blocked'])->toBeTrue()
        ->and(billingRacePayment($this->tenant)->amount_refunded_minor)->toBe(99900)
        ->and(billingRacePayment($this->tenant)->status)->toBe(PaymentStatus::PartiallyRefunded);
});

test('two invoices issued at once in two tenants take consecutive numbers of the one series', function (): void {
    $second = Tenant::factory()->onPlan('growth')->create(['slug' => 'bill-'.Str::lower(Str::random(10))]);

    $race = Race::run(
        holder: fn () => billingRaceSubscribe($this->tenant, $this->growth),
        contender: fn () => billingRaceSubscribe($second, $this->growth),
    );

    $numbers = [billingRaceIn($this->tenant, fn () => BillingInvoice::query()->sole()->number), billingRaceIn($second, fn () => BillingInvoice::query()->sole()->number)];
    [$a, $b] = array_map(fn (string $number): int => (int) Str::afterLast($number, '/'), $numbers);

    expect($race['blocked'])->toBeTrue()
        ->and($numbers[0])->not->toBe($numbers[1])
        ->and($b)->toBe($a + 1);
});

test('reconciliation and a webhook settling the same payment at once: applied once', function (): void {
    billingRaceSubscribe($this->tenant, $this->growth);
    $payment = billingRacePayment($this->tenant);
    billingRaceSettle($payment, PaymentStatus::Succeeded);
    $event = billingRaceEvent($payment, 'payment.succeeded');

    $race = Race::run(
        holder: fn () => billingRaceProcess($event),
        contender: fn () => billingRaceIn($this->tenant, fn () => app(BillingReconciler::class)->reconcile(apply: true)),
    );

    expect($race['blocked'])->toBeTrue()
        ->and(billingRaceIn($this->tenant, fn () => BillingInvoice::query()->sole()))->status->toBe(InvoiceStatus::Paid)
        ->and(billingRaceIn($this->tenant, fn () => BillingInvoice::query()->sole()->amount_paid_minor))->toBe(499900);
});
