<?php

use App\Enums\BillingInterval;
use App\Enums\PlatformRole;
use App\Models\AuditLog;
use App\Models\BillingPrice;
use App\Models\User;
use App\Services\Billing\Money;
use App\Services\Billing\PriceCatalogService;
use App\Services\Platform\Commercial\PlanAssignmentService;
use App\Services\Platform\PlatformAccess;
use App\Services\Tenancy\TenantContext;
use Tests\Feature\Billing\BillingWorld;

/*
 * SaaS-4: prices are versioned — a new amount replaces the current price, which is kept; nothing
 * is ever edited; subscriptions and invoices keep the price that applied.
 */
beforeEach(function (): void {
    $this->world = BillingWorld::build();
    $this->growth = app(PlanAssignmentService::class)->latestVersion('growth');
});

test('publishing a new amount retires the current price and keeps it as history', function (): void {
    $old = $this->world->prices['growth'];
    $new = app(PriceCatalogService::class)->publish($this->growth, Money::parse('5499.00', 'INR'), BillingInterval::Month);

    expect($old->fresh()->is_current)->toBeNull()
        ->and($old->fresh()->status)->toBe('retired')
        ->and($old->fresh()->effective_until)->not->toBeNull()
        ->and($new->is_current)->toBeTrue()
        ->and(app(PriceCatalogService::class)->current($this->growth, 'INR', BillingInterval::Month)->is($new))->toBeTrue()
        ->and(app(PriceCatalogService::class)->publish($this->growth, Money::parse('5499.00', 'INR'), BillingInterval::Month)->is($new))->toBeTrue()
        ->and(BillingPrice::query()->where('plan_version_id', $this->growth->id)->where('interval', 'month')->count())->toBe(2);
});

test('a price is never edited or deleted', function (): void {
    $price = $this->world->prices['growth']->fresh();

    expect(fn () => $price->update(['amount_minor' => 1]))->toThrow(LogicException::class, 'never edited')
        ->and(fn () => $price->fresh()->update(['currency' => 'USD']))->toThrow(LogicException::class)
        ->and(fn () => $price->fresh()->delete())->toThrow(LogicException::class);
});

test('a subscription keeps the price it bought; a retired price cannot be bought', function (): void {
    $subscription = $this->world->subscribe('paying');
    app(PriceCatalogService::class)->publish($this->growth, Money::parse('5999.00', 'INR'), BillingInterval::Month);

    expect($subscription->fresh()->amount_minor)->toBe(499900)
        ->and(fn () => $this->world->subscribe('other'))->toThrow(DomainException::class, 'replaced');
});

test('internal plans have no price, and only the platform publishes prices', function (): void {
    $legacy = app(PlanAssignmentService::class)->latestVersion('legacy');
    $tenantAdmin = $this->world->admins['paying']->fresh();
    $support = TenantContext::current()->runWithoutTenant(fn () => User::factory()->create(['email' => 'support@platform.test']));
    app(PlatformAccess::class)->grant($support, PlatformRole::Support, 'Support rota');

    expect(fn () => app(PriceCatalogService::class)->publish($legacy, Money::parse('1.00', 'INR'), BillingInterval::Month))->toThrow(DomainException::class, 'not offered')
        ->and(fn () => app(PriceCatalogService::class)->publish($this->growth, Money::parse('1.00', 'INR'), BillingInterval::Month, $tenantAdmin))->toThrow(DomainException::class, 'platform administrator')
        ->and(fn () => app(PriceCatalogService::class)->publish($this->growth, Money::parse('1.00', 'INR'), BillingInterval::Month, $support->fresh()))->toThrow(DomainException::class, 'platform administrator')
        ->and(TenantContext::current()->runWithoutTenant(fn () => AuditLog::query()->withoutTenancy()->whereNull('tenant_id')->where('action', 'billing_price_published')->count()))->toBe(3);
});

test('the console publishes a price exactly, refusing an inexact amount', function (): void {
    $this->artisan('billing:price', ['plan' => 'starter', 'amount' => '2499.50'])->expectsOutputToContain('INR 2,499.50')->assertSuccessful();
    $this->artisan('billing:price', ['plan' => 'starter', 'amount' => '2499.555'])->expectsOutputToContain('decimal places')->assertFailed();
    $this->artisan('billing:price', ['plan' => 'legacy', 'amount' => '1'])->assertFailed();
});
