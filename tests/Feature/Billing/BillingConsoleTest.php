<?php

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Role;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Billing\BillingWorld;

/*
 * SaaS-4: the platform's billing console — subscribe, change plan, cancel, record offline payments,
 * void, sweep — and the migration that gives every existing CHRO the billing permissions.
 */
beforeEach(function (): void {
    $this->world = BillingWorld::build();
});

test('platform staff subscribe, change the plan and cancel from the console, with a reason', function (): void {
    $this->artisan('billing:subscription', ['slug' => 'paying-co', 'action' => 'subscribe', '--plan' => 'growth', '--reason' => 'Signed order form'])->expectsOutputToContain('Awaiting first payment')->assertSuccessful();
    $this->world->pay('paying');
    $this->artisan('billing:subscription', ['slug' => 'paying-co', 'action' => 'change-plan', '--plan' => 'starter', '--reason' => 'Downgrade'])->assertSuccessful();
    $this->artisan('billing:subscription', ['slug' => 'paying-co', 'action' => 'cancel', '--reason' => 'Notice given'])->expectsOutputToContain('Ends at period end')->assertSuccessful();
    $this->artisan('billing:subscription', ['slug' => 'paying-co', 'action' => 'subscribe', '--plan' => 'growth'])->expectsOutputToContain('reason')->assertFailed();
    $this->artisan('billing:subscription', ['slug' => 'nobody', 'action' => 'subscribe'])->assertFailed();

    expect($this->world->subscription('paying')->status)->toBe(SubscriptionStatus::Cancelling)
        ->and($this->world->subscription('paying')->planVersion->plan->code)->toBe('starter');
});

test('an enterprise contract and its offline payment, from the console', function (): void {
    $this->artisan('billing:subscription', ['slug' => 'other-co', 'action' => 'subscribe', '--plan' => 'enterprise', '--source' => 'contract', '--amount' => '250000.00', '--interval' => 'year', '--contract' => 'MSA-7', '--reason' => 'Signed MSA'])->assertSuccessful();
    $number = $this->world->invoices('other')->sole()->number;

    $this->artisan('billing:payment', ['action' => 'record', 'target' => $number, 'amount' => '250000.00', '--reference' => 'NEFT 991', '--reason' => 'Received'])->assertSuccessful();
    $this->artisan('billing:payment', ['action' => 'void', 'target' => $number, '--reason' => 'x'])->expectsOutputToContain('no payment')->assertFailed();

    expect($this->world->invoices('other')->sole()->status)->toBe(InvoiceStatus::Paid);
});

test('the sweep visits every open tenant inside its own tenant, and one tenant\'s failure stops no other', function (): void {
    $this->world->subscribe('paying');
    $this->world->pay('paying');
    $this->world->subscribe('other');
    $this->world->pay('other');
    TenantContext::current()->run($this->world->tenant('other'), fn () => DB::table('billing_subscriptions')->where('tenant_id', $this->world->tenants['other']->id)->update(['interval' => 'fortnight']));
    $this->travel(1)->months();
    $this->travel(1)->minutes();

    $this->artisan('billing:sweep')->expectsOutputToContain('paying-co: renewed')->assertFailed();

    expect($this->world->invoices('paying'))->toHaveCount(2)
        ->and($this->world->invoices('other'))->toHaveCount(1);
});

test('the migration gives every tenant\'s CHRO the billing permissions, and nobody else', function (): void {
    $tenant = $this->world->tenant('paying');
    TenantContext::current()->run($tenant, function (): void {
        Role::byKeyOrFail('chro')->revokePermissionTo(['billing.view', 'billing.manage']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    });

    (require database_path('migrations/2026_10_04_144459_grant_billing_permissions.php'))->up();

    TenantContext::current()->run($tenant, function (): void {
        expect(Role::byKeyOrFail('chro')->fresh()->hasPermissionTo('billing.manage'))->toBeTrue()
            ->and(Role::byKeyOrFail('vp_hr')->fresh()->hasPermissionTo('billing.view'))->toBeFalse()
            ->and(Role::byKeyOrFail('manager')->fresh()->hasPermissionTo('billing.view'))->toBeFalse();
    });
});
