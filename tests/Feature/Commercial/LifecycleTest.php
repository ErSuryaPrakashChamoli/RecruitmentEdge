<?php

use App\Enums\OfferStatus;
use App\Enums\TenantStatus;
use App\Jobs\RunTenantScheduledTask;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\Offer;
use App\Models\Tenant;
use App\Services\Platform\Commercial\TenantLifecycleService;
use App\Services\Tenancy\TenantDirectory;
use App\Services\Tenancy\TenantTasks;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Commercial\CommercialWorld;

/*
 * SaaS-3: a tenant's lifecycle moves only along the transition table, under the tenant lock, with
 * a reason and an audit row. An ended trial is closed from the moment it ends — the hourly sweep
 * only records it. Work paused by a suspension resumes on reactivation; nothing is ever deleted.
 */
beforeEach(function (): void {
    $this->world = CommercialWorld::build();
    $this->lifecycle = app(TenantLifecycleService::class);
});

function lifecycleLastAudit(CommercialWorld $world, string $tenant): AuditLog
{
    return $world->in($tenant, fn (): AuditLog => AuditLog::query()->where('auditable_type', Tenant::class)->latest('id')->firstOrFail());
}

function lifecycleLapsedOffer(CommercialWorld $world, string $tenant): Offer
{
    return $world->in($tenant, fn (): Offer => Offer::factory()->create(['status' => OfferStatus::Released, 'offer_date' => now()->subDays(10), 'offer_expiry' => now()->subDays(3)]));
}

function lifecycleQueueExpiry(CommercialWorld $world, string $tenant): void
{
    // A statement, not an arrow function: the PendingDispatch must dispatch inside the tenant.
    $world->in($tenant, function (): void {
        RunTenantScheduledTask::dispatch('offers:expire-lapsed')->onConnection('database');
    });
}

function lifecycleWorkOnce(): void
{
    test()->artisan('queue:work', ['connection' => 'database', '--queue' => TenantTasks::QUEUED['offers:expire-lapsed'], '--once' => true, '--tries' => 1])->run();
}

test('allowed transitions are applied with their reason and audited old → new in the tenant\'s stream', function (): void {
    $growth = $this->world->tenant('growth');

    $this->lifecycle->markPastDue($growth, 'Invoice 42 overdue');
    $this->lifecycle->activate($growth, 'Paid');
    $this->lifecycle->suspend($growth, 'Policy breach');

    $audit = lifecycleLastAudit($this->world, 'growth');

    expect($this->world->tenant('growth')->status)->toBe(TenantStatus::Suspended)
        ->and($this->world->tenant('growth')->status_reason)->toBe('commercial')
        ->and($audit->action)->toBe('tenant_suspended')
        ->and($audit->old_values)->toBe(['status' => 'active'])
        ->and($audit->changes)->toMatchArray(['status' => 'suspended', 'source' => 'platform'])
        ->and($audit->reason)->toBe('Policy breach')
        ->and($this->world->in('growth', fn () => AuditLog::query()->whereIn('action', ['tenant_past_due', 'tenant_activated', 'tenant_suspended'])->count()))->toBe(3);
});

test('a transition outside the table, or without a reason, is refused and changes nothing', function (string $from, string $method): void {
    $tenant = Tenant::factory()->status(TenantStatus::from($from))->create(['slug' => "from-{$from}"]);
    $version = $tenant->entitlement_version;

    expect(fn () => $this->lifecycle->{$method}($tenant, 'Not allowed'))->toThrow(DomainException::class, 'cannot move')
        ->and($tenant->fresh()->status->value)->toBe($from)
        ->and($tenant->fresh()->entitlement_version)->toBe($version);
})->with([
    'active to trial is not a step' => ['active', 'markDeletionPending'],
    'cancelled never reopens' => ['cancelled', 'activate'],
    'deleted is final' => ['deleted', 'activate'],
    'provisioning cannot be suspended' => ['provisioning', 'suspend'],
    'past due cannot be cancelled directly' => ['past_due', 'cancel'],
]);

test('a lifecycle change needs a reason', function (): void {
    expect(fn () => $this->lifecycle->suspend($this->world->tenant('growth'), '  '))->toThrow(DomainException::class, 'reason')
        ->and($this->world->tenant('growth')->status)->toBe(TenantStatus::Active);
});

test('an ended trial is closed at once — sign-in, entitlements and background work — before any sweep', function (): void {
    $this->travel(15)->days();
    $tenant = $this->world->tenant('trialStarter');
    $admin = $this->world->admins['trialStarter']->fresh();

    expect($tenant->status)->toBe(TenantStatus::Trial)
        ->and($tenant->effectiveStatus())->toBe(TenantStatus::Suspended)
        ->and($admin->canAccessTenant($tenant))->toBeFalse()
        ->and(app(TenantDirectory::class)->forBackgroundWork()->pluck('slug'))->not->toContain('alpha-trial')
        ->and(app(TenantDirectory::class)->forBackgroundWork()->pluck('slug'))->toContain('bravo-growth');

    $this->actingAs($admin)->get('/admin/alpha-trial')->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
    $this->get('/careers/alpha-trial')->assertNotFound();
});

test('the sweep records an ended trial once, and leaves running trials alone', function (): void {
    $running = Tenant::factory()->trial(30)->onPlan('starter')->create(['slug' => 'still-trying']);
    $this->travel(15)->days();

    expect($this->lifecycle->sweep())->toBe(['expired' => 1, 'stale_provisioning' => 0])
        ->and($this->lifecycle->sweep())->toBe(['expired' => 0, 'stale_provisioning' => 0])
        ->and($this->world->tenant('trialStarter')->status)->toBe(TenantStatus::Suspended)
        ->and($this->world->tenant('trialStarter')->status_reason)->toBe('trial_expired')
        ->and(lifecycleLastAudit($this->world, 'trialStarter')->action)->toBe('trial_expired')
        ->and($running->fresh()->status)->toBe(TenantStatus::Trial);

    $this->artisan('tenants:lifecycle-sweep')->assertSuccessful();
});

test('a trial is extended while running, or re-opened once ended — nothing else becomes a trial again', function (): void {
    $endsAt = $this->world->tenant('trialStarter')->trial_ends_at;
    $this->lifecycle->extendTrial($this->world->tenant('trialStarter'), 7, 'Evaluation needs a week more');

    expect($this->world->tenant('trialStarter')->trial_ends_at->equalTo($endsAt->copy()->addDays(7)))->toBeTrue();

    $this->travel(22)->days();
    $this->lifecycle->sweep();
    $this->freezeSecond();
    $this->lifecycle->extendTrial($this->world->tenant('trialStarter'), 3, 'One more look');

    expect($this->world->tenant('trialStarter')->status)->toBe(TenantStatus::Trial)
        ->and($this->world->tenant('trialStarter')->isUsable())->toBeTrue()
        ->and($this->world->tenant('trialStarter')->trial_ends_at->equalTo(now()->addDays(3)))->toBeTrue()
        ->and(fn () => $this->lifecycle->extendTrial($this->world->tenant('growth'), 7, 'No'))->toThrow(DomainException::class, 'Only a trial')
        ->and(fn () => $this->lifecycle->extendTrial($this->world->tenant('suspended'), 7, 'No'))->toThrow(DomainException::class, 'Only a trial');
});

test('work queued before a suspension waits, and runs again when the tenant is reactivated', function (): void {
    $offer = lifecycleLapsedOffer($this->world, 'growth');
    lifecycleQueueExpiry($this->world, 'growth');
    $this->lifecycle->suspend($this->world->tenant('growth'), 'Commercial hold');

    lifecycleWorkOnce();

    expect(DB::table('failed_jobs')->count())->toBe(1)
        ->and($this->world->in('growth', fn () => $offer->fresh()->status))->toBe(OfferStatus::Released);

    $this->lifecycle->activate($this->world->tenant('growth'), 'Hold lifted');

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(lifecycleLastAudit($this->world, 'growth')->action)->toBe('tenant_work_resumed');

    lifecycleWorkOnce();

    expect($this->world->in('growth', fn () => $offer->fresh()->status))->toBe(OfferStatus::Expired);
});

test('only the reactivated tenant\'s paused work resumes, and a cancelled tenant\'s never does', function (): void {
    lifecycleQueueExpiry($this->world, 'growth');
    lifecycleQueueExpiry($this->world, 'enterprise');
    $this->lifecycle->suspend($this->world->tenant('growth'), 'Hold');
    $this->lifecycle->suspend($this->world->tenant('enterprise'), 'Hold');
    lifecycleWorkOnce();
    lifecycleWorkOnce();

    $this->lifecycle->cancel($this->world->tenant('enterprise'), 'Customer left');
    $this->lifecycle->activate($this->world->tenant('growth'), 'Hold lifted');

    $waiting = DB::table('failed_jobs')->pluck('payload')->map(fn (string $payload): int => json_decode($payload, true)['tenant_id']);

    expect($waiting->all())->toBe([$this->world->tenants['enterprise']->id])
        ->and(DB::table('jobs')->count())->toBe(1);
});

test('suspension, cancellation and deletion states never delete or change the tenant\'s data', function (): void {
    $this->world->in('growth', fn () => Candidate::factory()->count(3)->create());
    $offer = lifecycleLapsedOffer($this->world, 'growth');
    $candidates = $this->world->in('growth', fn () => Candidate::query()->count());
    $tenant = $this->world->tenant('growth');

    $this->lifecycle->suspend($tenant, 'Hold');
    $this->lifecycle->cancel($tenant, 'Left');
    $this->lifecycle->markDeletionPending($tenant, 'Retention period started');
    $this->lifecycle->markDeleted($tenant, 'Closed');

    expect($this->world->in('growth', fn () => Candidate::query()->count()))->toBe($candidates)
        ->and($this->world->in('growth', fn () => $offer->fresh()->status))->toBe(OfferStatus::Released)
        ->and($this->world->tenant('growth')->status)->toBe(TenantStatus::Deleted);
});

test('every change bumps the entitlement version, so no cached decision outlives it', function (): void {
    $version = $this->world->tenant('growth')->entitlement_version;

    $this->lifecycle->suspend($this->world->tenant('growth'), 'Hold');
    $this->lifecycle->activate($this->world->tenant('growth'), 'Lifted');

    expect($this->world->tenant('growth')->entitlement_version)->toBe($version + 2);
});

test('the console command follows the same table', function (): void {
    $this->artisan('tenants:lifecycle', ['slug' => 'bravo-growth', 'action' => 'suspend', '--reason' => 'Hold'])->expectsOutputToContain('Suspended')->assertSuccessful();
    $this->artisan('tenants:lifecycle', ['slug' => 'bravo-growth', 'action' => 'past-due', '--reason' => 'No'])->expectsOutputToContain('cannot move')->assertFailed();
    $this->artisan('tenants:lifecycle', ['slug' => 'bravo-growth', 'action' => 'activate'])->expectsOutputToContain('reason')->assertFailed();

    expect($this->world->tenant('growth')->status)->toBe(TenantStatus::Suspended);
});
