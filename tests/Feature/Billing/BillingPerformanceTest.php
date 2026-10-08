<?php

use App\Filament\Pages\Billing;
use App\Services\Billing\BillingClock;
use App\Services\Billing\BillingStatusService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Billing\BillingWorld;

/*
 * SaaS-4: billing reads are cheap and never call the provider while a page renders; the billing page
 * costs the same with 2 invoices or 24; the status is cached per tenant and billing version.
 */
beforeEach(function (): void {
    $this->world = BillingWorld::build();
    $this->world->subscribe('paying');
    $this->world->pay('paying');
});

function billingQueries(Closure $work): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $work();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

test('the billing page costs the same with 2 invoices or 24, and never asks the provider', function (): void {
    $this->actInTenant($this->world->tenant('paying'));
    $this->actingAs($this->world->admins['paying']->fresh());
    $this->world->provider()->outage = true;
    $render = fn (): int => billingQueries(fn () => Livewire::test(Billing::class)->assertOk());

    $this->travel(1)->months();
    $this->travel(1)->minutes();
    $this->world->in('paying', fn () => app(BillingClock::class)->tick());
    $render();
    $few = $render();

    for ($i = 0; $i < 22; $i++) {
        $this->travel(1)->months();
        $this->world->in('paying', fn () => app(BillingClock::class)->tick());
    }

    expect($this->world->invoices('paying')->count())->toBeGreaterThanOrEqual(24);

    $this->actInTenant($this->world->tenant('paying'));
    $render();

    expect($render())->toBe($few);
});

test('the billing status is read once per request and from the cache afterwards', function (): void {
    $service = fn (): BillingStatusService => app(BillingStatusService::class);
    $this->actInTenant($this->world->tenant('paying'));
    $service()->summary();

    expect(billingQueries(fn () => $service()->summary()))->toBe(0);

    app()->forgetScopedInstances();

    // A new request: the version (1 query), then the cached summary.
    expect(billingQueries(fn () => app(BillingStatusService::class)->summary()))->toBe(1);
});

test('processing a provider event costs a bounded number of queries', function (): void {
    $this->travel(1)->months();
    $this->travel(1)->minutes();
    $this->world->in('paying', fn () => app(BillingClock::class)->tick());

    expect(billingQueries(fn () => $this->world->pay('paying')->assertOk()))->toBeLessThanOrEqual(60);
});
