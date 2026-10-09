<?php

use App\Enums\PlatformCapability;
use App\Enums\SubscriptionSource;
use App\Filament\Platform\Pages\Invoices;
use App\Filament\Platform\Pages\Payments;
use App\Filament\Platform\Pages\Plans;
use App\Filament\Platform\Pages\Subscriptions;
use App\Filament\Platform\Pages\TenantDetail;
use App\Filament\Platform\Pages\Tenants;
use App\Filament\Platform\Widgets\CommercialOverview;
use App\Filament\Platform\Widgets\TenantMembers;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingSubscription;
use App\Models\Department;
use App\Models\PlanVersion;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Services\Billing\PaymentService;
use App\Services\Billing\SubscriptionService;
use App\Services\Platform\PlatformAuthorization;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Billing\BillingWorld;
use Tests\Feature\Platform\PlatformWorld;

/*
 * Platform commercial UI: the read side — tenant list, tenant detail tabs, members, plan catalog,
 * subscriptions, invoices, payments and the commercial overview. Metadata and stored commercial
 * records only, filtered on the server, at a cost that does not grow with the rows.
 */
beforeEach(function (): void {
    $this->platform = PlatformWorld::build($this->tenant);
    $this->billing = BillingWorld::build();
    Filament::setCurrentPanel('platform');
    $this->actingAs($this->platform->administrator);
});

function commercialViewsInvoice(Tenant $tenant): BillingInvoice
{
    return BillingInvoice::query()->withoutTenancy()->where('tenant_id', $tenant->id)->latest('id')->firstOrFail();
}

function commercialViewsQueries(Closure $work): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $work();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

/**
 * Subscribes and pays $count more tenants, each with one invoice and one payment.
 */
function commercialViewsMoreBilling(BillingWorld $billing, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        $tenant = Tenant::factory()->onPlan('growth')->create();
        app(SubscriptionService::class)->subscribe($tenant, SubscriptionSource::Manual, 'Signed up', price: $billing->prices['growth']->fresh());
        $invoice = commercialViewsInvoice($tenant);
        app(PaymentService::class)->recordManualPayment($invoice, $invoice->amountDue(), 'NEFT '.$i, 'Paid');
    }
}

test('the tenant list shows plan, version, subscription, billing state and owner, and filters them on the server', function (): void {
    $this->billing->subscribe('paying', 'growth', SubscriptionSource::Manual);
    $this->billing->subscribe('other', 'growth', SubscriptionSource::Manual);
    $invoice = commercialViewsInvoice($this->billing->tenant('other'));
    app(PaymentService::class)->recordManualPayment($invoice, $invoice->amountDue(), 'NEFT 1', 'Paid');
    [$paying, $other, $trialing] = [$this->billing->tenant('paying'), $this->billing->tenant('other'), $this->billing->tenant('trialing')];

    Livewire::test(Tenants::class)
        ->assertCanSeeTableRecords([$paying, $other, $trialing])
        ->assertTableColumnStateSet('plan_name', 'Growth', $paying)
        ->assertTableColumnStateSet('subscription_status', 'pending', $paying)
        ->assertTableColumnStateSet('billing_state', 'invoice_due', $paying)
        ->assertTableColumnStateSet('billing_state', 'up_to_date', $other)
        ->assertTableColumnStateSet('billing_state', 'no_subscription', $trialing)
        ->assertTableColumnStateSet('owner_name', $this->platform->identity->adminA->name, $this->tenant);

    Livewire::test(Tenants::class)->filterTable('plan', 'starter')->assertCanSeeTableRecords([$trialing])->assertCanNotSeeTableRecords([$paying, $other]);
    Livewire::test(Tenants::class)->filterTable('subscription', 'active')->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$paying, $trialing]);
    Livewire::test(Tenants::class)->filterTable('billing', 'invoice_due')->assertCanSeeTableRecords([$paying])->assertCanNotSeeTableRecords([$other, $trialing]);
    Livewire::test(Tenants::class)->filterTable('billing', 'no_subscription')->assertCanSeeTableRecords([$trialing])->assertCanNotSeeTableRecords([$paying, $other]);
    Livewire::test(Tenants::class)->filterTable('trial_ending', true)->assertCanNotSeeTableRecords([$trialing, $paying]);
    Livewire::test(Tenants::class)->searchTable('Paying')->assertCanSeeTableRecords([$paying])->assertCanNotSeeTableRecords([$other, $trialing]);

    // Billing's own verdict, not an invoice's due date: a past-due subscription is shown as such.
    BillingSubscription::query()->withoutTenancy()->where('tenant_id', $paying->id)->update(['status' => 'past_due']);
    Livewire::test(Tenants::class)
        ->assertTableColumnStateSet('billing_state', 'past_due', $paying)
        ->filterTable('billing', 'past_due')->assertCanSeeTableRecords([$paying])->assertCanNotSeeTableRecords([$other, $trialing]);
    Livewire::test(Tenants::class)->filterTable('billing', 'invoice_due')->assertCanNotSeeTableRecords([$paying, $other, $trialing]);
});

test('the tenant detail shows every tab to an administrator; support sees neither billing nor members', function (): void {
    $this->billing->subscribe('paying', 'growth', SubscriptionSource::Manual);
    $paying = $this->billing->tenant('paying');
    $invoice = commercialViewsInvoice($paying);

    Livewire::test(TenantDetail::class, ['tenant' => $paying->id])
        ->assertOk()
        ->assertSee(['Entitlements', 'Staff seats', 'Recent invoices', $invoice->number, $invoice->total()->format(), 'Pending invitations', 'Departments', 'Usage against plan limits', 'Latest platform actions', 'Health']);

    $this->actingAs($this->platform->support);

    Livewire::test(TenantDetail::class, ['tenant' => $paying->id])
        ->assertOk()
        ->assertSee(['Entitlements', 'Health'])
        ->assertDontSee(['Recent invoices', $invoice->number, 'Pending invitations', 'Latest platform actions']);
});

test('the configuration tab shows counts, never the records counted', function (): void {
    $this->actInTenant($this->tenant);
    $department = Department::factory()->create(['name' => 'Zircon Analytics Unit']);

    Livewire::test(TenantDetail::class, ['tenant' => $this->tenant->id])
        ->assertSee('Departments')
        ->assertDontSee($department->name);
});

test('the members table: name, email, membership, owner, roles — never a credential, never another tenant\'s member; tenant managers only', function (): void {
    $acmeAdmin = $this->platform->identity->adminA;
    $betaAdmin = $this->platform->identity->adminB;

    Livewire::test(TenantMembers::class, ['tenantId' => $this->tenant->id])
        ->assertCanSeeTableRecords(TenantMembership::query()->where('tenant_id', $this->tenant->id)->get())
        ->assertCanNotSeeTableRecords(TenantMembership::query()->where('tenant_id', '!=', $this->tenant->id)->get())
        ->assertSee([$acmeAdmin->email, 'Owner'])
        ->assertDontSee([(string) $acmeAdmin->getAuthPassword(), $betaAdmin->email]);

    foreach ([$this->platform->support, $this->platform->compliance] as $operator) {
        $this->actingAs($operator);
        Livewire::test(TenantMembers::class, ['tenantId' => $this->tenant->id])->assertForbidden();
    }
});

test('the plan catalog lists every published version with its grants, prices and tenants — and offers no way to edit them', function (): void {
    $growth = PlanVersion::query()->whereHas('plan', fn ($plan) => $plan->where('code', 'growth'))->sole();

    Livewire::test(Plans::class)
        ->assertCanSeeTableRecords(PlanVersion::query()->get())
        ->assertTableColumnStateSet('tenants_count', 2, $growth)
        ->assertTableActionExists('view')
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->mountTableAction('view', $growth)
        ->assertMountedActionModalSee(['Staff seats', 'INR 4,999.00', 'INR 49,990.00', 'Current']);
});

test('subscriptions, invoices and payments list every tenant\'s records, narrowed on the server by filter or tenant', function (): void {
    $this->billing->subscribe('paying', 'growth', SubscriptionSource::Manual);
    $this->billing->subscribe('other', 'growth', SubscriptionSource::Manual);
    $paid = commercialViewsInvoice($this->billing->tenant('other'));
    app(PaymentService::class)->recordManualPayment($paid, $paid->amountDue(), 'NEFT 1', 'Paid');
    $open = commercialViewsInvoice($this->billing->tenant('paying'));
    $subscriptions = BillingSubscription::query()->withoutTenancy()->get()->keyBy('tenant_id');
    $payment = BillingPayment::query()->withoutTenancy()->sole();

    Livewire::test(Subscriptions::class)
        ->assertCanSeeTableRecords($subscriptions->values())
        ->filterTable('status', 'active')
        ->assertCanSeeTableRecords([$subscriptions[$paid->tenant_id]])
        ->assertCanNotSeeTableRecords([$subscriptions[$open->tenant_id]]);

    Livewire::test(Invoices::class)->filterTable('status', 'open')->assertCanSeeTableRecords([$open])->assertCanNotSeeTableRecords([$paid]);
    Livewire::withQueryParams(['tenant' => $paid->tenant_id])->test(Invoices::class)->assertCanSeeTableRecords([$paid])->assertCanNotSeeTableRecords([$open]);
    Livewire::test(Payments::class)->assertCanSeeTableRecords([$payment])->filterTable('status', 'failed')->assertCanNotSeeTableRecords([$payment]);
});

test('the commercial overview and the commercial pages are for administrators only', function (): void {
    $this->billing->subscribe('paying', 'growth', SubscriptionSource::Manual);

    Livewire::test(CommercialOverview::class)->assertSee(['Subscriptions needing attention', 'Open invoices', 'INR 4,999.00']);

    foreach ([$this->platform->support, $this->platform->compliance] as $operator) {
        $this->actingAs($operator);

        expect(app(PlatformAuthorization::class)->can($operator, PlatformCapability::CommercialManage))->toBeFalse()
            ->and(CommercialOverview::canView())->toBeFalse();

        foreach ([Plans::class, Subscriptions::class, Invoices::class, Payments::class] as $page) {
            $this->get($page::getUrl(panel: 'platform'))->assertForbidden();
        }
    }

    $this->actingAs($this->platform->identity->adminA)->get(Invoices::getUrl(panel: 'platform'))->assertRedirect(Filament::getPanel('platform')->getLoginUrl());
});

test('the commercial pages cost the same with three times the tenants and billing records', function (): void {
    $pages = [
        'tenants' => fn () => Livewire::test(Tenants::class)->assertOk(),
        'subscriptions' => fn () => Livewire::test(Subscriptions::class)->assertOk(),
        'invoices' => fn () => Livewire::test(Invoices::class)->assertOk(),
        'payments' => fn () => Livewire::test(Payments::class)->assertOk(),
        'plans' => fn () => Livewire::test(Plans::class)->assertOk(),
        'tenant detail' => fn () => Livewire::test(TenantDetail::class, ['tenant' => $this->billing->tenants['paying']->id])->assertOk(),
    ];

    $this->billing->subscribe('paying', 'growth', SubscriptionSource::Manual);
    commercialViewsMoreBilling($this->billing, 2);
    array_map(fn (Closure $render) => commercialViewsQueries($render), $pages);
    $few = array_map(fn (Closure $render) => commercialViewsQueries($render), $pages);

    commercialViewsMoreBilling($this->billing, 6);
    $many = array_map(fn (Closure $render) => commercialViewsQueries($render), $pages);

    expect($many)->toBe($few);
});
