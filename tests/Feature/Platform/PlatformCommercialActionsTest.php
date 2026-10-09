<?php

use App\Enums\BillingInterval;
use App\Enums\Entitlement;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Filament\Platform\Pages\Invoices;
use App\Filament\Platform\Pages\Payments;
use App\Filament\Platform\Pages\TenantDetail;
use App\Models\AuditLog;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingPrice;
use App\Models\BillingSubscription;
use App\Models\Tenant;
use App\Services\Billing\PaymentService;
use App\Services\Platform\PlatformDirectory;
use App\Services\Platform\TenantCommercialService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Billing\BillingWorld;
use Tests\Feature\Platform\PlatformWorld;

/*
 * Platform commercial UI: the high-risk commercial actions — plan, overrides, subscriptions,
 * payments, refunds, voids — reach the existing SaaS-3 / SaaS-4 services only through
 * TenantCommercialService, for platform administrators only, bound to the tenant on screen. A
 * repeated submission never duplicates commercial state: the services refuse or return it.
 */
beforeEach(function (): void {
    $this->platform = PlatformWorld::build($this->tenant);
    $this->billing = BillingWorld::build();
    Filament::setCurrentPanel('platform');
    $this->actingAs($this->platform->administrator);
});

function commercialActionsOn(Tenant $tenant): mixed
{
    return Livewire::test(TenantDetail::class, ['tenant' => $tenant->id]);
}

function commercialActionsSubscription(Tenant $tenant): ?BillingSubscription
{
    return BillingSubscription::query()->withoutTenancy()->where('tenant_id', $tenant->id)->latest('id')->first();
}

function commercialActionsInvoices(Tenant $tenant): mixed
{
    return BillingInvoice::query()->withoutTenancy()->where('tenant_id', $tenant->id)->orderBy('id')->get();
}

function commercialActionsEntitlement(Tenant $tenant, Entitlement $entitlement): array
{
    return collect(app(PlatformDirectory::class)->entitlements($tenant->fresh()))->firstWhere('entitlement', $entitlement);
}

test('an administrator changes the plan from the tenant detail: attributed, and the same change again writes nothing', function (): void {
    $tenant = $this->billing->tenant('paying');

    commercialActionsOn($tenant)->callAction('changePlan', ['plan' => 'starter', 'reason' => 'Smaller team'])->assertNotified('Plan changed');

    expect(app(PlatformDirectory::class)->currentAssignment($tenant)['code'])->toBe('starter')
        ->and(AuditLog::query()->withoutTenancy()->where('tenant_id', $tenant->id)->where('action', 'plan_changed')->sole())
        ->actor_kind->toBe('platform')
        ->user_id->toBe($this->platform->administrator->id)
        // The commercial change is part of the platform audit, so the tenant's Audit tab lists it.
        ->and(app(PlatformDirectory::class)->recentAudit($tenant)->pluck('action')->all())->toContain('plan_changed');

    $assignments = DB::table('tenant_plan_assignments')->where('tenant_id', $tenant->id)->count();
    app(TenantCommercialService::class)->assignPlan($tenant, 'starter', 'Smaller team', $this->platform->administrator);

    expect(DB::table('tenant_plan_assignments')->where('tenant_id', $tenant->id)->count())->toBe($assignments);
});

test('plan changes need a reason and an offered plan', function (): void {
    $tenant = $this->billing->tenant('paying');

    commercialActionsOn($tenant)->callAction('changePlan', ['plan' => 'starter', 'reason' => ''])->assertHasActionErrors(['reason']);

    expect(fn () => app(TenantCommercialService::class)->assignPlan($tenant, 'legacy', 'Back to legacy', $this->platform->administrator))->toThrow(DomainException::class)
        ->and(fn () => app(TenantCommercialService::class)->assignPlan($tenant, 'starter', '  ', $this->platform->administrator))->toThrow(DomainException::class)
        ->and(app(PlatformDirectory::class)->currentAssignment($tenant)['code'])->toBe('growth');
});

test('entitlement overrides are set and removed through the entitlement service; the effective value follows', function (): void {
    $tenant = $this->billing->tenant('paying');

    commercialActionsOn($tenant)->callAction('setOverride', ['entitlement' => Entitlement::MembersActiveMax->value, 'unlimited' => false, 'limit' => 40, 'reason' => 'Pilot'])->assertNotified('Override set');
    commercialActionsOn($tenant)->callAction('setOverride', ['entitlement' => Entitlement::ApiAccess->value, 'enabled' => true, 'reason' => 'Integration pilot'])->assertNotified('Override set');

    expect(commercialActionsEntitlement($tenant, Entitlement::MembersActiveMax))->effective->toBe('40')->source->toBe('override')
        ->and(commercialActionsEntitlement($tenant, Entitlement::ApiAccess))->effective->toBe('Included');

    commercialActionsOn($tenant)->callAction('removeOverride', ['entitlement' => Entitlement::MembersActiveMax->value, 'reason' => 'Pilot ended'])->assertNotified('Override removed');

    expect(commercialActionsEntitlement($tenant, Entitlement::MembersActiveMax))->override->toBeNull()->effective->toBe(commercialActionsEntitlement($tenant, Entitlement::MembersActiveMax)['plan'])
        ->and(DB::table('tenant_entitlement_overrides')->where('tenant_id', $tenant->id)->where('is_current', true)->count())->toBe(1);
});

test('an override needs a reason and a value of its type', function (): void {
    $tenant = $this->billing->tenant('paying');

    commercialActionsOn($tenant)->callAction('setOverride', ['entitlement' => Entitlement::MembersActiveMax->value, 'limit' => -1, 'reason' => ''])->assertHasActionErrors(['limit', 'reason']);

    expect(fn () => app(TenantCommercialService::class)->setOverride($tenant, Entitlement::MembersActiveMax, true, 'Wrong type', null, $this->platform->administrator))->toThrow(DomainException::class)
        ->and(fn () => app(TenantCommercialService::class)->removeOverride($tenant, Entitlement::ApiAccess, '', $this->platform->administrator))->toThrow(DomainException::class)
        ->and(DB::table('tenant_entitlement_overrides')->where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('a manual subscription starts at a current price, issues its invoice, and the same request again returns it', function (): void {
    $tenant = $this->billing->tenant('paying');

    commercialActionsOn($tenant)->callAction('subscribe', ['source' => 'manual', 'price_id' => $this->billing->prices['growth']->id, 'reason' => 'Signed up'])->assertNotified('Subscription started');

    expect(commercialActionsSubscription($tenant))->status->toBe(SubscriptionStatus::Pending)->source->toBe(SubscriptionSource::Manual)
        ->and(commercialActionsInvoices($tenant))->toHaveCount(1);

    app(TenantCommercialService::class)->subscribe($tenant, SubscriptionSource::Manual, ['price_id' => $this->billing->prices['growth']->id], 'Signed up', $this->platform->administrator);

    expect(BillingSubscription::query()->withoutTenancy()->where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and(commercialActionsInvoices($tenant))->toHaveCount(1);
});

test('a contract subscription takes its negotiated amount in the chosen billing currency', function (): void {
    $tenant = $this->billing->tenant('paying');

    commercialActionsOn($tenant)->callAction('subscribe', [
        'source' => 'contract', 'plan' => 'growth', 'amount' => '1200.00', 'currency' => 'USD', 'interval' => BillingInterval::Year->value,
        'contract_reference' => 'MSA-2026-07', 'reason' => 'Annual agreement',
    ])->assertNotified('Subscription started');

    expect(commercialActionsSubscription($tenant))
        ->status->toBe(SubscriptionStatus::Active)
        ->currency->toBe('USD')
        ->amount_minor->toBe(120000)
        ->contract_reference->toBe('MSA-2026-07');
});

test('provider billing is never started from the panel', function (): void {
    expect(fn () => app(TenantCommercialService::class)->subscribe($this->billing->tenant('paying'), SubscriptionSource::Provider, ['price_id' => $this->billing->prices['growth']->id], 'Signed up', $this->platform->administrator))
        ->toThrow(DomainException::class)
        ->and(commercialActionsSubscription($this->billing->tenant('paying')))->toBeNull();
});

test('the subscription plan changes to another current price once; the same price again changes nothing', function (): void {
    $tenant = $this->billing->tenant('paying');
    $this->billing->subscribe('paying', 'growth', SubscriptionSource::Manual);
    app(PaymentService::class)->recordManualPayment(commercialActionsInvoices($tenant)->sole(), commercialActionsInvoices($tenant)->sole()->amountDue(), 'NEFT 1', 'Paid');

    commercialActionsOn($tenant)->callAction('changeSubscriptionPlan', ['price_id' => $this->billing->prices['starter']->id, 'reason' => 'Downgrade'])->assertNotified('Subscription plan changed');
    app(TenantCommercialService::class)->changeSubscriptionPlan($tenant, $this->billing->prices['starter']->id, 'Downgrade', $this->platform->administrator);

    expect(commercialActionsSubscription($tenant)->billing_price_id)->toBe($this->billing->prices['starter']->id)
        ->and(AuditLog::query()->withoutTenancy()->where('tenant_id', $tenant->id)->where('action', 'subscription_plan_changed')->count())->toBe(1);
});

test('cancelling at period end, then now: each once — a repeat is refused — and cancelling now needs the slug typed', function (): void {
    $tenant = $this->billing->tenant('paying');
    $this->billing->subscribe('paying', 'growth', SubscriptionSource::Manual);
    app(PaymentService::class)->recordManualPayment(commercialActionsInvoices($tenant)->sole(), commercialActionsInvoices($tenant)->sole()->amountDue(), 'NEFT 1', 'Paid');

    commercialActionsOn($tenant)->callAction('cancelAtPeriodEnd', ['reason' => 'Customer leaving'])->assertNotified('Subscription ends at period end');

    expect(commercialActionsSubscription($tenant)->status)->toBe(SubscriptionStatus::Cancelling)
        ->and(fn () => app(TenantCommercialService::class)->cancelSubscription($tenant, false, 'Again', $this->platform->administrator))->toThrow(DomainException::class);

    commercialActionsOn($tenant)->callAction('cancelNow', ['confirm' => 'not-the-slug', 'reason' => 'Fraud'])->assertHasActionErrors(['confirm']);
    expect(commercialActionsSubscription($tenant)->status)->toBe(SubscriptionStatus::Cancelling);

    commercialActionsOn($tenant)->callAction('cancelNow', ['confirm' => $tenant->slug, 'reason' => 'Fraud'])->assertNotified('Subscription cancelled');

    expect(commercialActionsSubscription($tenant)->status)->toBe(SubscriptionStatus::Cancelled)
        ->and(fn () => app(TenantCommercialService::class)->cancelSubscription($tenant, true, 'Again', $this->platform->administrator))->toThrow(DomainException::class);
});

test('recording a payment settles the whole balance once; a second submission is refused and records nothing', function (): void {
    $tenant = $this->billing->tenant('paying');
    $this->billing->subscribe('paying', 'growth', SubscriptionSource::Manual);
    $invoice = commercialActionsInvoices($tenant)->sole();

    Livewire::test(Invoices::class)->callTableAction('recordPayment', $invoice, ['external_reference' => 'NEFT UTR 991', 'reason' => 'Bank transfer'])->assertNotified('Payment recorded');

    expect($invoice->fresh())->status->toBe(InvoiceStatus::Paid)->amount_due_minor->toBe(0)
        ->and(commercialActionsSubscription($tenant)->status)->toBe(SubscriptionStatus::Active)
        ->and(BillingPayment::query()->withoutTenancy()->where('billing_invoice_id', $invoice->id)->sole())->amount_minor->toBe($invoice->total_minor)->external_reference->toBe('NEFT UTR 991');

    expect(fn () => app(TenantCommercialService::class)->recordFullPayment($tenant, $invoice->id, 'NEFT UTR 991', 'Bank transfer', $this->platform->administrator))->toThrow(DomainException::class)
        ->and(BillingPayment::query()->withoutTenancy()->where('billing_invoice_id', $invoice->id)->count())->toBe(1);

    Livewire::test(Invoices::class)->assertTableActionHidden('recordPayment', $invoice->fresh());
});

test('a refund returns what remains of a payment once; a second submission is refused', function (): void {
    $tenant = $this->billing->tenant('paying');
    $this->billing->subscribe('paying', 'growth', SubscriptionSource::Manual);
    $invoice = commercialActionsInvoices($tenant)->sole();
    $payment = app(PaymentService::class)->recordManualPayment($invoice, $invoice->amountDue(), 'NEFT 1', 'Paid');

    Livewire::test(Payments::class)->callTableAction('refund', $payment->fresh(), ['confirm' => 'wrong', 'reason' => 'Goodwill'])->assertHasTableActionErrors(['confirm']);
    Livewire::test(Payments::class)->callTableAction('refund', $payment->fresh(), ['confirm' => $invoice->number, 'reason' => 'Goodwill'])->assertNotified('Refund recorded');

    expect($payment->fresh())->status->toBe(PaymentStatus::Refunded)->amount_refunded_minor->toBe($payment->amount_minor);

    expect(fn () => app(TenantCommercialService::class)->refundRemaining($tenant, $payment->id, 'Goodwill', $this->platform->administrator))->toThrow(DomainException::class)
        ->and($payment->fresh()->amount_refunded_minor)->toBe($payment->amount_minor);
});

test('voiding an unpaid invoice needs its number typed, happens once, and keeps the number', function (): void {
    $tenant = $this->billing->tenant('paying');
    $this->billing->subscribe('paying', 'growth', SubscriptionSource::Manual);
    $invoice = commercialActionsInvoices($tenant)->sole();

    Livewire::test(Invoices::class)->callTableAction('void', $invoice, ['confirm' => $invoice->number, 'reason' => 'Issued in error'])->assertNotified('Invoice voided');

    expect($invoice->fresh())->status->toBe(InvoiceStatus::Void)->number->toBe($invoice->number)
        ->and(fn () => app(TenantCommercialService::class)->voidInvoice($tenant, $invoice->id, 'Again', $this->platform->administrator))->toThrow(DomainException::class);
});

test('a billing record of another tenant is never reached through the tenant on screen', function (): void {
    $this->billing->subscribe('paying', 'growth', SubscriptionSource::Manual);
    $invoice = commercialActionsInvoices($this->billing->tenant('paying'))->sole();
    $payment = app(PaymentService::class)->recordManualPayment($invoice, $invoice->amountDue(), 'NEFT 1', 'Paid');
    $other = $this->billing->tenant('other');
    $commercial = app(TenantCommercialService::class);

    expect(fn () => $commercial->voidInvoice($other, $invoice->id, 'Wrong tenant', $this->platform->administrator))->toThrow(DomainException::class, 'not one of')
        ->and(fn () => $commercial->recordFullPayment($other, $invoice->id, 'X', 'Wrong tenant', $this->platform->administrator))->toThrow(DomainException::class, 'not one of')
        ->and(fn () => $commercial->refundRemaining($other, $payment->id, 'Wrong tenant', $this->platform->administrator))->toThrow(DomainException::class, 'not one of')
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Succeeded);
});

test('only administrators see or perform commercial actions; the adapter refuses support and compliance on its own', function (): void {
    $tenant = $this->billing->tenant('paying');
    $commercial = app(TenantCommercialService::class);

    foreach ([$this->platform->support, $this->platform->compliance] as $operator) {
        $this->actingAs($operator);

        commercialActionsOn($tenant)
            ->assertActionHidden('changePlan')
            ->assertActionHidden('setOverride')
            ->assertActionHidden('subscribe')
            ->assertActionHidden('cancelNow');

        expect(fn () => $commercial->assignPlan($tenant, 'starter', 'x', $operator))->toThrow(DomainException::class)
            ->and(fn () => $commercial->setOverride($tenant, Entitlement::ApiAccess, true, 'x', null, $operator))->toThrow(DomainException::class)
            ->and(fn () => $commercial->subscribe($tenant, SubscriptionSource::Manual, ['price_id' => $this->billing->prices['growth']->id], 'x', $operator))->toThrow(DomainException::class);

        $this->get(Invoices::getUrl(panel: 'platform'))->assertForbidden();
        $this->get(Payments::getUrl(panel: 'platform'))->assertForbidden();
    }

    expect(commercialActionsSubscription($tenant))->toBeNull()
        ->and(app(PlatformDirectory::class)->currentAssignment($tenant)['code'])->toBe('growth');
});

test('no UI action edits a published price or an issued invoice', function (): void {
    $tenant = $this->billing->tenant('paying');
    $prices = BillingPrice::query()->orderBy('id')->get(['id', 'amount_minor', 'is_current'])->toArray();

    commercialActionsOn($tenant)->callAction('subscribe', ['source' => 'manual', 'price_id' => $this->billing->prices['growth']->id, 'reason' => 'Signed up']);
    $invoice = commercialActionsInvoices($tenant)->sole();
    $issued = $invoice->only(['number', 'subtotal_minor', 'tax_minor', 'total_minor', 'currency', 'period_start', 'issued_at']);
    commercialActionsOn($tenant)->callAction('changePlan', ['plan' => 'starter', 'reason' => 'Smaller team']);
    Livewire::test(Invoices::class)->callTableAction('recordPayment', $invoice, ['external_reference' => 'NEFT 2', 'reason' => 'Paid']);

    expect(BillingPrice::query()->orderBy('id')->get(['id', 'amount_minor', 'is_current'])->toArray())->toBe($prices)
        ->and($invoice->fresh()->only(array_keys($issued)))->toEqual($issued);
});
