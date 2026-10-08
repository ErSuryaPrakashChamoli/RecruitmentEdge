<?php

use App\Enums\BillingEventStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\ProcessBillingEvent;
use App\Models\BillingEvent;
use App\Models\BillingPayment;
use App\Models\TenantPlanAssignment;
use App\Services\Billing\BillingEventProcessor;
use App\Services\Billing\BillingReferencePending;
use App\Services\Billing\Providers\BillingProviderManager;
use App\Services\Billing\Providers\FakeBillingProvider;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Billing\BillingWorld;

/*
 * SaaS-4: provider notifications are untrusted input. Only a signed, fresh request is read; each
 * event is applied once however often it arrives; the tenant, plan and amount are never taken from
 * the payload; an event that does not match our own records changes nothing.
 */
beforeEach(function (): void {
    $this->world = BillingWorld::build();
    $this->world->subscribe('paying');
    $this->payment = $this->world->lastPayment('paying');
});

function webhookEvents(): int
{
    return TenantContext::current()->runWithoutTenant(fn (): int => BillingEvent::query()->count());
}

test('a correctly signed event is stored once, acknowledged and applied', function (): void {
    $this->world->pay('paying', 'evt_once')->assertOk()->assertExactJson(['received' => true]);

    $event = BillingEvent::query()->where('provider_event_id', 'evt_once')->sole();

    expect($event->status)->toBe(BillingEventStatus::Processed)
        ->and($event->tenant_id)->toBe($this->world->tenants['paying']->id)
        ->and($event->payload_hash)->toHaveLength(64)
        ->and($this->world->subscription('paying')->status)->toBe(SubscriptionStatus::Active);
});

test('forged, tampered, unsigned and replayed requests are refused before anything is read', function (): void {
    $body = json_encode(['id' => 'evt_forged', 'type' => 'payment.succeeded', 'created' => now()->getTimestamp(), 'data' => ['payment' => $this->payment->provider_payment_ref, 'amount' => '4999.00', 'currency' => 'INR']]);

    $this->world->postWebhook($body, secret: 'not-the-secret')->assertUnauthorized();
    $this->world->postWebhook($body, timestamp: now()->subMinutes(10)->getTimestamp())->assertUnauthorized();
    $this->world->postWebhook($body, timestamp: now()->addMinutes(10)->getTimestamp())->assertUnauthorized();
    $this->call('POST', '/webhooks/billing/fake', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertUnauthorized();

    // Signed, then altered in transit.
    $signature = FakeBillingProvider::signature($body, now()->getTimestamp(), BillingWorld::WEBHOOK_SECRET);
    $this->call('POST', '/webhooks/billing/fake', [], [], [], ['HTTP_FAKE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], str_replace('4999.00', '1.00', $body))->assertUnauthorized();

    expect(webhookEvents())->toBe(0)
        ->and($this->world->subscription('paying')->status)->toBe(SubscriptionStatus::Pending);
});

test('unknown providers, unreadable events and unsupported types change nothing', function (): void {
    $this->post('/webhooks/billing/stripe', [])->assertNotFound();
    $this->world->postWebhook('not json at all')->assertUnprocessable();
    $this->world->postWebhook(json_encode(['id' => 'evt_other', 'type' => 'customer.updated', 'created' => now()->getTimestamp(), 'data' => []]))->assertOk();

    expect(BillingEvent::query()->where('provider_event_id', 'evt_other')->sole()->status)->toBe(BillingEventStatus::Ignored)
        ->and(BillingEvent::query()->where('provider_event_id', 'evt_other')->sole()->note)->toBe('unsupported event')
        ->and($this->world->subscription('paying')->status)->toBe(SubscriptionStatus::Pending);
});

test('the same event delivered again — or the same outcome in a new event — is applied once', function (): void {
    $this->world->pay('paying', 'evt_dup')->assertOk();
    $this->world->pay('paying', 'evt_dup')->assertOk()->assertJson(['duplicate' => true]);
    $this->world->pay('paying', 'evt_dup_2')->assertOk();

    expect(BillingEvent::query()->where('provider_event_id', 'evt_dup_2')->sole()->note)->toBe('stale')
        ->and($this->world->invoices('paying')->sole()->amount_paid_minor)->toBe(499900)
        ->and(webhookEvents())->toBe(2);
});

test('out of order: a late "failed" never undoes "succeeded"; a provider retry may turn "failed" into "succeeded"', function (): void {
    $this->world->decline('paying');

    expect($this->world->lastPayment('paying')->status)->toBe(PaymentStatus::Failed);

    $this->world->pay('paying')->assertOk();
    $this->world->webhook('payment.failed', $this->world->lastPayment('paying'), ['failure_code' => 'late'])->assertOk();

    expect($this->world->lastPayment('paying')->status)->toBe(PaymentStatus::Succeeded)
        ->and($this->world->invoices('paying')->sole()->status)->toBe(InvoiceStatus::Paid)
        ->and($this->world->subscription('paying')->status)->toBe(SubscriptionStatus::Active);
});

test('an event for a payment not recorded yet is retried, then set aside; our echoed reference finds it', function (): void {
    Queue::fake();
    $this->world->webhook('payment.succeeded', $this->payment, ['payment' => 'fpay_unknown', 'reference' => '6b0f5f4e-2a6f-4d7e-9c1a-0d6c1c3a9b11'], 'evt_early')->assertOk();
    $event = BillingEvent::query()->where('provider_event_id', 'evt_early')->sole();

    expect(fn () => app(BillingEventProcessor::class)->process($event->id, finalAttempt: false))->toThrow(BillingReferencePending::class)
        ->and(app(BillingEventProcessor::class)->process($event->id, finalAttempt: true))->toBe('unknown payment reference')
        ->and($event->fresh()->status)->toBe(BillingEventStatus::Ignored);

    // The provider id is not recorded yet, but our own reference (echoed by the provider) is.
    TenantContext::current()->run($this->world->tenant('paying'), fn () => BillingPayment::query()->whereKey($this->payment->id)->update(['provider_payment_ref' => null]));
    $this->world->webhook('payment.succeeded', $this->payment->fresh(), ['payment' => $this->payment->provider_payment_ref], 'evt_by_reference')->assertOk();

    expect(app(BillingEventProcessor::class)->process(BillingEvent::query()->where('provider_event_id', 'evt_by_reference')->sole()->id))->toBe('applied')
        ->and($this->world->lastPayment('paying')->provider_payment_ref)->toBe($this->payment->provider_payment_ref);

    Queue::assertPushed(ProcessBillingEvent::class, 2);
});

test('a signed event never moves money it does not match: amount, currency or customer', function (): void {
    $this->world->webhook('payment.succeeded', $this->payment, ['amount' => '1.00'], 'evt_amount')->assertOk();
    $this->world->webhook('payment.succeeded', $this->payment, ['currency' => 'USD'], 'evt_currency')->assertOk();
    $this->world->webhook('payment.succeeded', $this->payment, ['customer' => 'fcus_someone_else'], 'evt_customer')->assertOk();

    expect(BillingEvent::query()->where('provider_event_id', 'evt_amount')->sole()->note)->toBe('amount mismatch')
        ->and(BillingEvent::query()->where('provider_event_id', 'evt_currency')->sole()->note)->toBe('amount mismatch')
        ->and(BillingEvent::query()->where('provider_event_id', 'evt_customer')->sole()->note)->toBe('customer mismatch')
        ->and($this->world->invoices('paying')->sole()->status)->toBe(InvoiceStatus::Open)
        ->and($this->world->subscription('paying')->status)->toBe(SubscriptionStatus::Pending);
});

test('the tenant, plan and status in a payload are never trusted: only our own records decide', function (): void {
    $this->world->webhook('payment.succeeded', $this->payment, ['tenant_id' => $this->world->tenants['other']->id, 'plan' => 'enterprise', 'status' => 'active'], 'evt_claims')->assertOk();

    expect(BillingEvent::query()->where('provider_event_id', 'evt_claims')->sole()->tenant_id)->toBe($this->world->tenants['paying']->id)
        ->and($this->world->subscription('other'))->toBeNull()
        ->and($this->world->in('paying', fn () => TenantPlanAssignment::query()->where('is_current', true)->sole()->planVersion->plan->code))->toBe('growth');
});

test('payloads, signatures and secrets never reach the logs', function (): void {
    Log::spy();
    $this->world->postWebhook('{"id":"evt_x","type":"payment.succeeded","data":{"card":"4242424242424242"}}', secret: 'wrong')->assertUnauthorized();
    $this->world->pay('paying');

    Log::shouldNotHaveReceived('warning', fn (string $message, array $context = []): bool => str_contains(json_encode($context), '4242') || str_contains(json_encode($context), BillingWorld::WEBHOOK_SECRET));
    Log::shouldNotHaveReceived('info', fn (string $message, array $context = []): bool => str_contains(json_encode($context), BillingWorld::WEBHOOK_SECRET));
});

test('the fake provider is refused outside local and testing: no fake-signed payment is ever accepted', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    app()->forgetScopedInstances();

    expect(app(BillingProviderManager::class)->find('fake'))->toBeNull();

    $this->world->webhook('payment.succeeded', $this->payment)->assertNotFound();

    expect($this->world->subscription('paying')->status)->toBe(SubscriptionStatus::Pending)
        ->and(webhookEvents())->toBe(0);
});
