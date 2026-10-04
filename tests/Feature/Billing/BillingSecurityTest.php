<?php

use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Filament\Pages\Billing;
use App\Models\BillingCustomer;
use App\Models\BillingEvent;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingSubscription;
use App\Models\Role;
use App\Models\User;
use App\Services\Billing\BillingCustomerService;
use App\Services\Billing\BillingReconciler;
use App\Services\Billing\BillingStatusService;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\Money;
use App\Services\Billing\PaymentService;
use App\Services\Billing\SubscriptionService;
use App\Services\Tenancy\TenantCache;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Feature\Billing\BillingWorld;

/*
 * SaaS-4: billing is the most sensitive tenant data. One tenant never sees or changes another's
 * subscription, invoices, payments, contact or provider ids — on any path — and a tenant
 * administrator is not a billing administrator unless granted.
 */
beforeEach(function (): void {
    $this->world = BillingWorld::build();
    $this->world->subscribe('paying');
    $this->world->pay('paying');
    $this->world->subscribe('other', 'starter');
});

test('every billing record is scoped to its tenant: another tenant\'s rows are never visible', function (): void {
    $this->world->in('other', function (): void {
        expect(BillingSubscription::query()->count())->toBe(1)
            ->and(BillingInvoice::query()->count())->toBe(1)
            ->and(BillingPayment::query()->count())->toBe(1)
            ->and(BillingCustomer::query()->count())->toBe(1)
            ->and(BillingInvoice::query()->pluck('tenant_id')->unique()->all())->toBe([$this->world->tenants['other']->id])
            ->and(BillingPayment::query()->where('reference', $this->world->lastPayment('paying')->reference)->exists())->toBeFalse();
    });
});

test('the billing page shows the organisation\'s own subscription and invoices; another\'s is 404', function (): void {
    $this->actInTenant($this->world->tenant('paying'));
    $this->actingAs($this->world->admins['paying']->fresh());
    $own = $this->world->invoices('paying')->sole()->number;
    $theirs = $this->world->invoices('other')->sole()->number;

    $this->get('/admin/paying-co/billing')->assertOk()->assertSee($own)->assertSee('Growth v1')->assertDontSee($theirs)->assertDontSee('Starter v1')->assertDontSee((string) $this->world->customerRef('other'));
    $this->get('/admin/other-co/billing')->assertNotFound();
});

test('a tenant administrator is not a billing administrator: billing.view and billing.manage are their own', function (): void {
    $this->actInTenant($this->world->tenant('paying'));
    $hrAdmin = $this->world->in('paying', function (): User {
        $role = Role::findOrCreate('tenant_admin');
        $role->givePermissionTo(['users.manage', 'roles.manage', 'settings.manage']);

        return User::factory()->create()->assignRole($role);
    });
    $viewer = $this->world->in('paying', function (): User {
        $role = Role::findOrCreate('billing_viewer');
        $role->givePermissionTo('billing.view');

        return User::factory()->create()->assignRole($role);
    });

    expect($this->world->in('paying', fn () => $hrAdmin->fresh()->can('users.manage')))->toBeTrue();
    $this->actingAs($hrAdmin->fresh())->get('/admin/paying-co/billing')->assertForbidden();

    $this->actingAs($viewer->fresh());
    $this->get('/admin/paying-co/billing')->assertOk();
    Livewire::test(Billing::class)
        ->assertActionHidden(TestAction::make('cancelAtPeriodEnd'))
        ->assertActionHidden(TestAction::make('editDetails'));

    // The services refuse on their own, whatever the page shows.
    expect(fn () => $this->world->in('paying', fn () => app(SubscriptionService::class)->cancelAtPeriodEnd($this->world->subscription('paying'), 'x', $viewer->fresh())))->toThrow(DomainException::class, 'billing.manage')
        ->and(fn () => $this->world->in('paying', fn () => app(BillingCustomerService::class)->updateDetails(['legal_name' => 'Hijacked Ltd'], $viewer->fresh())))->toThrow(DomainException::class, 'billing.manage')
        ->and($this->world->subscription('paying')->status)->toBe(SubscriptionStatus::Active);
});

test('a billing administrator acts on the organisation\'s own subscription only', function (): void {
    $admin = $this->world->admins['paying']->fresh();

    expect(fn () => $this->world->in('paying', fn () => app(SubscriptionService::class)->cancelAtPeriodEnd($this->world->in('other', fn () => BillingSubscription::query()->sole()), 'x', $admin)))->toThrow(DomainException::class, 'another organisation')
        ->and($this->world->subscription('other')->status)->toBe(SubscriptionStatus::Pending);
});

test('the billing contact must be a member here, and being it grants nothing', function (): void {
    $admin = $this->world->admins['paying']->fresh();
    $member = $this->world->in('paying', fn () => User::factory()->create()->assignRole('recruiter'));
    $outsider = $this->world->admins['other']->fresh();

    expect(fn () => $this->world->in('paying', fn () => app(BillingCustomerService::class)->setContact($outsider, $admin)))->toThrow(DomainException::class, 'active member');

    $this->world->in('paying', fn () => app(BillingCustomerService::class)->setContact($member, $admin));

    expect($this->world->in('paying', fn () => BillingCustomer::query()->value('contact_user_id')))->toBe($member->id)
        ->and($this->world->in('paying', fn () => $member->fresh()->can('billing.view')))->toBeFalse()
        ->and($this->world->in('paying', fn () => $member->fresh()->getRoleNames()->all()))->toBe(['recruiter']);
});

test('plans, immediate cancellation, refunds, voids, offline payments and reconciliation are platform-only', function (): void {
    $admin = $this->world->admins['paying']->fresh();
    $subscription = $this->world->subscription('paying');

    expect(fn () => app(SubscriptionService::class)->subscribe($this->world->tenant('trialing'), SubscriptionSource::Provider, 'x', $admin, $this->world->prices['growth']->fresh()))->toThrow(DomainException::class, 'platform administrator')
        ->and(fn () => app(SubscriptionService::class)->changePlan($subscription, $this->world->prices['starter'], 'x', $admin))->toThrow(DomainException::class, 'platform administrator')
        ->and(fn () => app(SubscriptionService::class)->cancelForPlatform($subscription, 'x', $admin, immediately: true))->toThrow(DomainException::class, 'platform administrator')
        ->and(fn () => app(PaymentService::class)->refund($this->world->lastPayment('paying'), Money::parse('1.00', 'INR'), 'x', $admin))->toThrow(DomainException::class, 'platform administrator')
        ->and(fn () => app(InvoiceService::class)->void($this->world->invoices('other')->sole(), 'x', $admin))->toThrow(DomainException::class, 'platform administrator')
        ->and(fn () => $this->world->in('paying', fn () => app(BillingReconciler::class)->reconcile(operator: $admin)))->toThrow(DomainException::class, 'platform administrator')
        ->and($this->world->subscription('paying')->status)->toBe(SubscriptionStatus::Active);
});

test('the billing status cache is per tenant and per billing version: a change is never answered from the old entry', function (): void {
    $paying = $this->world->in('paying', fn () => app(BillingStatusService::class)->summary());
    $other = $this->world->in('other', fn () => app(BillingStatusService::class)->summary());
    $version = $this->world->in('paying', fn () => BillingCustomer::query()->value('state_version'));

    expect($paying['plan'])->toBe('Growth v1')
        ->and($other['plan'])->toBe('Starter v1')
        ->and(Cache::has(TenantCache::key('billing:status:v'.$version, $this->world->tenants['paying']->id)))->toBeTrue();

    $this->world->in('paying', fn () => app(SubscriptionService::class)->cancelAtPeriodEnd($this->world->subscription('paying'), 'x', $this->world->admins['paying']->fresh()));
    app()->forgetScopedInstances();

    expect($this->world->in('paying', fn () => app(BillingStatusService::class)->summary())['status'])->toBe('cancelling');
});

test('provider events never land in a tenant\'s audit or billing views until matched to its own payment', function (): void {
    $this->world->webhook('payment.succeeded', $this->world->lastPayment('other'), ['customer' => $this->world->customerRef('paying')], 'evt_cross')->assertOk();

    expect(BillingEvent::query()->where('provider_event_id', 'evt_cross')->sole()->note)->toBe('customer mismatch')
        ->and($this->world->subscription('other')->status)->toBe(SubscriptionStatus::Pending)
        ->and($this->world->subscription('paying')->status)->toBe(SubscriptionStatus::Active);
});
