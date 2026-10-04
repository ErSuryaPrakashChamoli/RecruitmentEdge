<?php

use App\Enums\BillingInterval;
use App\Enums\Entitlement;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\AuditLog;
use App\Models\BillingSubscription;
use App\Models\TenantPlanAssignment;
use App\Models\User;
use App\Services\Billing\BillingClock;
use App\Services\Billing\Money;
use App\Services\Billing\PriceCatalogService;
use App\Services\Billing\SubscriptionService;
use App\Services\Billing\SubscriptionStateMachine;
use App\Services\Entitlements\EntitlementService;
use App\Services\Platform\Commercial\PlanAssignmentService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Billing\BillingWorld;

/*
 * SaaS-4: a subscription's life — created once, paid, renewed, overdue, suspended, recovered,
 * cancelled — each step through the canonical state machine, each carried into SaaS-3 (plan and
 * lifecycle) by the one bridge. Nothing is ever deleted.
 */
beforeEach(function (): void {
    $this->world = BillingWorld::build();
});

function lifecycleTick(BillingWorld $world, string $tenant): array
{
    return $world->in($tenant, fn (): array => app(BillingClock::class)->tick());
}

test('subscribing issues the first invoice and asks the provider to collect it; payment activates', function (): void {
    $subscription = $this->world->subscribe('paying');
    $invoice = $this->world->invoices('paying')->sole();
    $payment = $this->world->lastPayment('paying');

    expect($subscription->status)->toBe(SubscriptionStatus::Pending)
        ->and($subscription->money()->format())->toBe('INR 4,999.00')
        ->and($invoice->status)->toBe(InvoiceStatus::Open)
        ->and($invoice->total_minor)->toBe(499900)
        ->and($invoice->number)->toMatch('#^RE/\d{4}-\d{2}/\d{6}$#')
        ->and($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->provider_payment_ref)->toStartWith('fpay_')
        ->and($payment->amount_minor)->toBe(499900);

    $this->world->pay('paying')->assertOk()->assertJson(['received' => true]);

    expect($this->world->subscription('paying')->status)->toBe(SubscriptionStatus::Active)
        ->and($this->world->invoices('paying')->sole()->status)->toBe(InvoiceStatus::Paid)
        ->and($this->world->lastPayment('paying')->status)->toBe(PaymentStatus::Succeeded)
        ->and($this->world->tenant('paying')->status)->toBe(TenantStatus::Active);
});

test('subscribing again is idempotent; a different subscription is refused while one is live', function (): void {
    $first = $this->world->subscribe('paying');
    $again = $this->world->subscribe('paying');

    expect($again->id)->toBe($first->id)
        ->and($this->world->in('paying', fn () => BillingSubscription::query()->count()))->toBe(1)
        ->and($this->world->invoices('paying'))->toHaveCount(1)
        ->and(fn () => $this->world->subscribe('paying', 'starter'))->toThrow(DomainException::class, 'change its plan');
});

test('during a SaaS-3 trial the subscription trials, buys the plan, and is invoiced only when the trial ends', function (): void {
    $subscription = $this->world->subscribe('trialing', 'growth');

    expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($subscription->current_period_start->equalTo($this->world->tenant('trialing')->trial_ends_at->startOfSecond()))->toBeTrue()
        ->and($this->world->invoices('trialing'))->toHaveCount(0)
        ->and($this->world->tenant('trialing')->status)->toBe(TenantStatus::Trial)
        ->and($this->world->in('trialing', fn () => TenantPlanAssignment::query()->where('is_current', true)->sole()->planVersion->plan->code))->toBe('growth');

    $this->travel(15)->days();
    lifecycleTick($this->world, 'trialing');

    expect($this->world->invoices('trialing'))->toHaveCount(1)
        ->and($this->world->tenant('trialing')->isUsable())->toBeFalse();

    $this->world->pay('trialing')->assertOk();

    expect($this->world->subscription('trialing')->status)->toBe(SubscriptionStatus::Active)
        ->and($this->world->tenant('trialing')->status)->toBe(TenantStatus::Active)
        ->and($this->world->tenant('trialing')->isUsable())->toBeTrue();
});

test('a failed payment makes it past due with a grace period; the tenant stays usable until the grace ends', function (): void {
    $this->world->subscribe('paying');
    $this->world->pay('paying');
    $this->travel(1)->months();
    $this->travel(1)->minutes();
    lifecycleTick($this->world, 'paying');

    expect($this->world->invoices('paying'))->toHaveCount(2);

    $this->world->decline('paying')->assertOk();
    $subscription = $this->world->subscription('paying');
    $tenant = $this->world->tenant('paying');

    expect($subscription->status)->toBe(SubscriptionStatus::PastDue)
        ->and($subscription->grace_ends_at->equalTo($subscription->past_due_since->copy()->addDays(7)))->toBeTrue()
        ->and($tenant->status)->toBe(TenantStatus::PastDue)
        ->and($tenant->access_ends_at->equalTo($subscription->grace_ends_at))->toBeTrue()
        ->and($tenant->isUsable())->toBeTrue()
        ->and($this->world->lastPayment('paying')->failure_message)->toBe('Your card was declined.');

    // The grace period's end closes access at once — before any sweep records it.
    $this->travel(8)->days();

    expect($this->world->tenant('paying')->isUsable())->toBeFalse()
        ->and($this->world->in('paying', fn () => app(EntitlementService::class)->allows(Entitlement::ExportsData)))->toBeFalse();

    lifecycleTick($this->world, 'paying');

    expect($this->world->subscription('paying')->status)->toBe(SubscriptionStatus::Unpaid)
        ->and($this->world->tenant('paying')->status)->toBe(TenantStatus::Suspended)
        ->and($this->world->tenant('paying')->status_reason)->toBe('billing_unpaid');
});

test('paying the overdue invoice reactivates — once, however often the provider says so', function (): void {
    $this->world->subscribe('paying');
    $this->world->pay('paying');
    $this->travel(1)->months();
    $this->travel(1)->minutes();
    lifecycleTick($this->world, 'paying');
    $this->world->decline('paying');
    $this->travel(8)->days();
    lifecycleTick($this->world, 'paying');

    // The provider retries the card; a new attempt is collected and succeeds.
    $this->travel(1)->days();
    lifecycleTick($this->world, 'paying');
    $this->world->pay('paying', 'evt_recovery')->assertOk();
    $this->world->pay('paying', 'evt_recovery')->assertOk()->assertJson(['duplicate' => true]);
    $this->world->pay('paying', 'evt_recovery_again')->assertOk();

    expect($this->world->subscription('paying')->status)->toBe(SubscriptionStatus::Active)
        ->and($this->world->tenant('paying')->status)->toBe(TenantStatus::Active)
        ->and($this->world->tenant('paying')->access_ends_at)->toBeNull()
        ->and($this->world->in('paying', fn () => AuditLog::query()->where('action', 'subscription_active')->count()))->toBe(2)
        ->and($this->world->in('paying', fn () => AuditLog::query()->where('action', 'tenant_activated')->count()))->toBe(1);
});

test('renewal issues the next period\'s invoice in advance from the price snapshot, not today\'s price', function (): void {
    $this->world->subscribe('paying');
    $this->world->pay('paying');
    app(PriceCatalogService::class)->publish(app(PlanAssignmentService::class)->latestVersion('growth'), Money::parse('5999.00', 'INR'), BillingInterval::Month);

    $this->travel(1)->months();
    $this->travel(1)->minutes();
    lifecycleTick($this->world, 'paying');
    lifecycleTick($this->world, 'paying');

    $invoices = $this->world->invoices('paying');

    expect($invoices)->toHaveCount(2)
        ->and($invoices[1]->total_minor)->toBe(499900)
        ->and($invoices[1]->period_start->equalTo($invoices[0]->period_end))->toBeTrue()
        ->and($this->world->lastPayment('paying')->status)->toBe(PaymentStatus::Pending);
});

test('cancelling at period end keeps access until then, a resume undoes it, and the end suspends without deleting', function (): void {
    $this->world->subscribe('paying');
    $this->world->pay('paying');
    $admin = $this->world->admins['paying']->fresh();
    $subscription = $this->world->subscription('paying');

    $this->world->in('paying', fn () => app(SubscriptionService::class)->cancelAtPeriodEnd($subscription, 'Budget', $admin));

    expect($this->world->subscription('paying')->status)->toBe(SubscriptionStatus::Cancelling)
        ->and($this->world->tenant('paying')->access_ends_at->equalTo($subscription->current_period_end))->toBeTrue()
        ->and($this->world->tenant('paying')->isUsable())->toBeTrue();

    $this->world->in('paying', fn () => app(SubscriptionService::class)->resume($this->world->subscription('paying'), $admin));

    expect($this->world->subscription('paying')->status)->toBe(SubscriptionStatus::Active)
        ->and($this->world->tenant('paying')->access_ends_at)->toBeNull();

    $this->world->in('paying', fn () => app(SubscriptionService::class)->cancelAtPeriodEnd($this->world->subscription('paying'), 'Budget', $admin));
    $this->travel(1)->months();
    $this->travel(1)->minutes();

    expect($this->world->tenant('paying')->isUsable())->toBeFalse();

    lifecycleTick($this->world, 'paying');

    expect($this->world->subscription('paying')->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($this->world->subscription('paying')->is_live)->toBeNull()
        ->and($this->world->tenant('paying')->status)->toBe(TenantStatus::Suspended)
        ->and($this->world->tenant('paying')->status_reason)->toBe('subscription_ended')
        ->and($this->world->invoices('paying'))->toHaveCount(1)
        ->and($this->world->in('paying', fn () => User::query()->count()))->toBeGreaterThan(0);
});

test('a plan change moves entitlements now and bills the new price from the next period', function (): void {
    $this->world->subscribe('paying', 'starter');
    $this->world->pay('paying');

    expect($this->world->in('paying', fn () => app(EntitlementService::class)->allows(Entitlement::AiAssistant)))->toBeFalse();

    app(SubscriptionService::class)->changePlan($this->world->subscription('paying'), $this->world->prices['growth'], 'Upgrade');

    expect($this->world->in('paying', fn () => app(EntitlementService::class)->allows(Entitlement::AiAssistant)))->toBeTrue()
        ->and($this->world->invoices('paying')->sole()->total_minor)->toBe(199900);

    $this->travel(1)->months();
    $this->travel(1)->minutes();
    lifecycleTick($this->world, 'paying');

    expect($this->world->invoices('paying')->last()->total_minor)->toBe(499900)
        ->and(fn () => app(SubscriptionService::class)->changePlan($this->world->subscription('paying'), $this->world->prices['growthYearly'], 'Yearly'))->toThrow(DomainException::class, 'interval');
});

test('a contract starts active by an explicit platform decision — never by a fake payment', function (): void {
    $subscription = app(SubscriptionService::class)->subscribe($this->world->tenant('other'), SubscriptionSource::Contract, 'Enterprise agreement', contractPlan: app(PlanAssignmentService::class)->latestVersion('enterprise'), contractAmount: Money::parse('250000.00', 'INR'), contractInterval: BillingInterval::Year, contractReference: 'MSA-2026-014');

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->contract_reference)->toBe('MSA-2026-014')
        ->and($this->world->lastPayment('other'))->toBeNull()
        ->and($this->world->invoices('other')->sole()->status)->toBe(InvoiceStatus::Open)
        ->and($this->world->in('other', fn () => app(EntitlementService::class)->effective(Entitlement::MembersActiveMax)->unlimited))->toBeTrue();
});

test('a subscription never paid expires without touching the tenant it never served', function (): void {
    $this->world->subscribe('paying');
    $this->world->decline('paying');
    $this->travel(8)->days();
    lifecycleTick($this->world, 'paying');

    expect($this->world->subscription('paying')->status)->toBe(SubscriptionStatus::Expired)
        ->and($this->world->tenant('paying')->status)->toBe(TenantStatus::Active)
        ->and($this->world->tenant('paying')->isUsable())->toBeTrue();
});

test('the state machine refuses every transition outside its table, and an ended subscription stays ended', function (): void {
    $subscription = $this->world->subscribe('paying');
    $machine = app(SubscriptionStateMachine::class);

    expect(fn () => $this->world->in('paying', fn () => DB::transaction(fn () => $machine->transition($subscription->fresh(), SubscriptionStatus::PastDue, 'x', 'test'))))->toThrow(DomainException::class, 'cannot move')
        ->and(fn () => $this->world->in('paying', fn () => DB::transaction(fn () => $machine->transition($subscription->fresh(), SubscriptionStatus::Cancelling, 'x', 'test'))))->toThrow(DomainException::class, 'cannot move');

    app(SubscriptionService::class)->cancelForPlatform($subscription, 'Left', immediately: true);

    expect(fn () => $this->world->in('paying', fn () => DB::transaction(fn () => $machine->transition($this->world->subscription('paying'), SubscriptionStatus::Active, 'x', 'test'))))->toThrow(DomainException::class, 'cannot move')
        ->and(fn () => app(SubscriptionService::class)->cancelForPlatform($this->world->subscription('paying'), 'Again'))->toThrow(DomainException::class, 'ended');
});
