<?php

use App\Enums\OfferStatus;
use App\Enums\TenantStatus;
use App\Jobs\RunTenantScheduledTask;
use App\Models\Offer;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/*
 * SaaS-1: queued and scheduled work runs in exactly the tenant it was queued for — restored from
 * the payload by the worker, whatever ran before; refused when the payload and its context
 * disagree or the tenant may not have work done; the scheduler only enumerates tenants.
 * Each tenant has one offer whose validity lapsed, so the effect of a run is visible per tenant.
 */
beforeEach(function (): void {
    $this->alpha = Tenant::factory()->create(['slug' => 'alpha']);
    $this->bravo = Tenant::factory()->create(['slug' => 'bravo']);
    $lapsed = fn () => Offer::factory()->create(['status' => OfferStatus::Released, 'offer_date' => now()->subDays(10), 'offer_expiry' => now()->subDays(3)]);
    $this->alphaOffer = TenantContext::current()->run($this->alpha, $lapsed);
    $this->bravoOffer = TenantContext::current()->run($this->bravo, $lapsed);
});

function asyncOfferStatus(Tenant $tenant, Offer $offer): OfferStatus
{
    return TenantContext::current()->run($tenant, fn () => Offer::query()->findOrFail($offer->id)->status);
}

/**
 * Queues the offer-expiry task inside the tenant (as tenants:dispatch does) on the database queue.
 */
function asyncQueueIn(Tenant $tenant): void
{
    TenantContext::current()->run($tenant, function (): void {
        RunTenantScheduledTask::dispatch('offers:expire-lapsed')->onConnection('database');
    });
}

function asyncWorkOnce(): void
{
    test()->artisan('queue:work', ['connection' => 'database', '--queue' => 'default', '--once' => true, '--tries' => 1])->run();
}

test('a job queued inside a tenant declares that tenant in its payload and in the context it carries', function (): void {
    asyncQueueIn($this->alpha);

    $payload = json_decode((string) DB::table('jobs')->value('payload'), true);

    expect($payload['tenant_id'])->toBe($this->alpha->id)
        ->and(unserialize($payload['illuminate:log:context']['data']['tenant_id']))->toBe($this->alpha->id);
});

test('the worker runs a job in its own tenant, whatever tenant the process worked in before', function (): void {
    asyncQueueIn($this->alpha);
    TenantContext::current()->setTenant($this->bravo);

    asyncWorkOnce();

    expect(asyncOfferStatus($this->alpha, $this->alphaOffer))->toBe(OfferStatus::Expired)
        ->and(asyncOfferStatus($this->bravo, $this->bravoOffer))->toBe(OfferStatus::Released);
});

test('a job whose payload names another tenant than its context is refused, and changes nothing', function (): void {
    asyncQueueIn($this->alpha);
    $payload = json_decode((string) DB::table('jobs')->value('payload'), true);
    DB::table('jobs')->update(['payload' => json_encode([...$payload, 'tenant_id' => $this->bravo->id])]);

    asyncWorkOnce();

    expect(DB::table('failed_jobs')->count())->toBe(1)
        ->and(asyncOfferStatus($this->alpha, $this->alphaOffer))->toBe(OfferStatus::Released)
        ->and(asyncOfferStatus($this->bravo, $this->bravoOffer))->toBe(OfferStatus::Released);
});

test('queued work for a tenant that may no longer have work done is refused', function (): void {
    asyncQueueIn($this->alpha);
    $this->alpha->update(['status' => TenantStatus::Suspended]);

    asyncWorkOnce();

    expect(DB::table('failed_jobs')->count())->toBe(1)
        ->and(asyncOfferStatus($this->alpha, $this->alphaOffer))->toBe(OfferStatus::Released);
});

test('the scheduler queues one run per active tenant, each declaring its own tenant, and none for an inactive one', function (): void {
    config(['queue.default' => 'database']);
    Tenant::factory()->status(TenantStatus::Suspended)->create(['slug' => 'dormant']);

    $this->artisan('tenants:dispatch', ['task' => 'offers:expire-lapsed'])->assertSuccessful();
    $this->artisan('tenants:dispatch', ['task' => 'db:wipe'])->assertFailed();

    // The payload — what the worker restores — not just the job object, names each tenant.
    $declared = DB::table('jobs')->pluck('payload')->map(fn (string $payload) => json_decode($payload, true))
        ->map(fn (array $payload): array => [$payload['tenant_id'], unserialize($payload['illuminate:log:context']['data']['tenant_id'])])
        ->sortBy(0)->values()->all();

    expect($declared)->toBe([[$this->tenant->id, $this->tenant->id], [$this->alpha->id, $this->alpha->id], [$this->bravo->id, $this->bravo->id]]);
});

test('a long task runs in every active tenant, each inside its own tenant', function (): void {
    $this->artisan('tenants:run', ['task' => 'offers:expire-lapsed', '--all' => true])->assertSuccessful();

    expect(asyncOfferStatus($this->alpha, $this->alphaOffer))->toBe(OfferStatus::Expired)
        ->and(asyncOfferStatus($this->bravo, $this->bravoOffer))->toBe(OfferStatus::Expired);
});

test('an operator runs a task in a named tenant only, and never an arbitrary command', function (): void {
    $this->artisan('tenants:run', ['task' => 'offers:expire-lapsed', '--tenant' => ['bravo']])->assertSuccessful();
    $this->artisan('tenants:run', ['task' => 'db:wipe', '--tenant' => ['bravo']])->assertFailed();
    $this->artisan('tenants:run', ['task' => 'offers:expire-lapsed'])->assertFailed();

    expect(asyncOfferStatus($this->bravo, $this->bravoOffer))->toBe(OfferStatus::Expired)
        ->and(asyncOfferStatus($this->alpha, $this->alphaOffer))->toBe(OfferStatus::Released);
});
