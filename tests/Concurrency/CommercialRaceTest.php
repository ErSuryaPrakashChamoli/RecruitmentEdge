<?php

use App\Enums\Entitlement;
use App\Enums\RequisitionStatus;
use App\Enums\TenantStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\RecruitmentRequisition;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\TenantPlanAssignment;
use App\Models\User;
use App\Services\Entitlements\EntitlementDenied;
use App\Services\Entitlements\EntitlementService;
use App\Services\Entitlements\LimitReached;
use App\Services\Identity\TenantInvitationService;
use App\Services\Platform\Commercial\EntitlementOverrideService;
use App\Services\Platform\Commercial\PlanAssignmentService;
use App\Services\Platform\Commercial\PlanCatalogService;
use App\Services\Platform\Commercial\ProvisioningRequest;
use App\Services\Platform\Commercial\TenantDefaults;
use App\Services\Platform\Commercial\TenantLifecycleService;
use App\Services\Platform\Commercial\TenantProvisioningService;
use App\Services\RequisitionService;
use App\Services\Tenancy\TenantCache;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concurrency\Race;

/*
 * SaaS-3: limits, plan changes, overrides, lifecycle and provisioning under real two-transaction
 * concurrency (MySQL 8.4, REPEATABLE READ). The tenant row is the commercial lock: every
 * consumption and every commercial change takes it, so the contender waits and then decides on
 * the committed outcome. Run with: vendor/bin/pest -c phpunit.concurrency.xml
 */
beforeEach(function (): void {
    Race::prepareDatabase();
    Storage::fake('local');
    Mail::fake();
    app(PlanCatalogService::class)->sync();

    $this->tenant = Tenant::factory()->onPlan('starter')->create(['slug' => 'race-'.Str::lower(Str::random(10))]);
    TenantContext::current()->setTenant($this->tenant->fresh());
    (new RolePermissionSeeder)->run();
    $this->admin = User::factory()->create(['email' => 'admin.'.Str::random(8).'@commercial-race.test', 'employee_id' => Employee::factory()->create()->id])->assignRole('chro');
});

afterEach(function (): void {
    TenantContext::current()->setTenant(Tenant::query()->where('slug', 'concurrency')->firstOrFail());
});

function commercialRaceRequisitions(int $count): void
{
    RecruitmentRequisition::factory()->count($count)->create(['status' => RequisitionStatus::Open]);
}

function commercialRaceCreate(User $admin): RecruitmentRequisition
{
    // A request loads its tenant fresh: the forked contender must not reuse the holder's copy.
    TenantContext::current()->setTenant(Tenant::query()->findOrFail(TenantContext::current()->requireId()));

    return app(RequisitionService::class)->create(User::query()->findOrFail($admin->id), fn () => RecruitmentRequisition::factory()->create());
}

function commercialRaceActive(): int
{
    return RecruitmentRequisition::query()->whereNotIn('status', [RequisitionStatus::Closed->value, RequisitionStatus::Cancelled->value])->count();
}

test('two creates for the last place: one wins, the other waits and is refused', function (): void {
    commercialRaceRequisitions(9);

    $race = Race::run(
        holder: fn () => commercialRaceCreate($this->admin),
        contender: fn () => commercialRaceCreate($this->admin),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['exception'])->toBe(LimitReached::class)
        ->and(commercialRaceActive())->toBe(10);
});

test('a downgrade committed while a create waits applies to that create', function (): void {
    Race::pinPlan($this->tenant, 'growth');
    TenantContext::current()->setTenant($this->tenant->fresh());
    commercialRaceRequisitions(10);
    $starter = app(PlanAssignmentService::class)->latestVersion('starter');

    $race = Race::run(
        holder: fn () => app(PlanAssignmentService::class)->assign($this->tenant->fresh(), $starter, 'platform', 'Downgrade'),
        contender: fn () => commercialRaceCreate($this->admin),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['exception'])->toBe(LimitReached::class)
        ->and(commercialRaceActive())->toBe(10)
        ->and(TenantPlanAssignment::query()->where('is_current', true)->sole()->plan_version_id)->toBe($starter->id);
});

test('an override removed while a create waits no longer counts for it', function (): void {
    commercialRaceRequisitions(10);
    app(EntitlementOverrideService::class)->set($this->tenant->fresh(), Entitlement::RequisitionsActiveMax, 11, 'Migration headroom');

    $race = Race::run(
        holder: fn () => app(EntitlementOverrideService::class)->remove($this->tenant->fresh(), Entitlement::RequisitionsActiveMax, 'Migration done'),
        contender: fn () => commercialRaceCreate($this->admin),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['exception'])->toBe(LimitReached::class)
        ->and(commercialRaceActive())->toBe(10);
});

test('two invitations accepted for the last seat: one joins, the other waits and is refused', function (): void {
    User::factory()->count(3)->create()->each->assignRole('recruiter');
    $invite = fn (string $email): TenantInvitation => app(TenantInvitationService::class)->invite(['email' => $email, 'roles' => [Role::byKeyOrFail('recruiter')->id]], $this->admin->fresh());
    $first = $invite('first.'.Str::random(6).'@commercial-race.test');
    $second = $invite('second.'.Str::random(6).'@commercial-race.test');

    $race = Race::run(
        holder: fn () => app(TenantInvitationService::class)->acceptAsNewIdentity($first, 'First Person', 'Tr1cky-Ledger-Horse'),
        contender: fn () => app(TenantInvitationService::class)->acceptAsNewIdentity(TenantInvitation::query()->findOrFail($second->id), 'Second Person', 'Tr1cky-Ledger-Horse'),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['exception'])->toBe(LimitReached::class)
        ->and(app(EntitlementService::class)->usage(Entitlement::MembersActiveMax))->toBe(5)
        ->and(User::query()->where('email', $second->email)->exists())->toBeFalse();
});

test('a suspension committed while an operation waits refuses that operation', function (): void {
    $race = Race::run(
        holder: fn () => app(TenantLifecycleService::class)->suspend($this->tenant->fresh(), 'Commercial hold'),
        contender: fn () => commercialRaceCreate($this->admin),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['exception'])->toBe(EntitlementDenied::class)
        ->and($race['message'])->toContain('not active')
        ->and(commercialRaceActive())->toBe(0);
});

test('a trial that ends while a request waits for the tenant lock refuses that request', function (): void {
    $endsAt = now()->addSeconds(4);
    Tenant::query()->whereKey($this->tenant->id)->update(['status' => TenantStatus::Trial->value, 'trial_started_at' => now()->subDays(14), 'trial_ends_at' => $endsAt]);

    // The request passes its permission check during the trial, then waits on the tenant lock held
    // by a commercial operation; meanwhile the trial ends and the sweep records it.
    $race = Race::run(
        holder: fn () => Tenant::query()->whereKey($this->tenant->id)->lockForUpdate()->first(),
        contender: fn () => commercialRaceCreate($this->admin),
        whileBlocked: function () use ($endsAt): array {
            while (now()->lte($endsAt)) {
                usleep(100_000);
            }

            return app(TenantLifecycleService::class)->sweep();
        },
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['observed']['expired'])->toBeGreaterThanOrEqual(1)
        ->and($race['exception'])->toBe(EntitlementDenied::class)
        ->and($this->tenant->fresh()->status_reason)->toBe('trial_expired')
        ->and(commercialRaceActive())->toBe(0);
});

test('the trial sweep and a trial extension: whichever commits first, the extension stands', function (): void {
    Tenant::query()->whereKey($this->tenant->id)->update(['status' => TenantStatus::Trial->value, 'trial_started_at' => now()->subDays(14), 'trial_ends_at' => now()->subMinute()]);

    // The sweep listed the tenant as ended, then waits for the extension and re-checks it.
    $race = Race::run(
        holder: fn () => app(TenantLifecycleService::class)->extendTrial($this->tenant->fresh(), 7, 'Extended on request'),
        contender: fn () => app(TenantLifecycleService::class)->sweep(),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['completed'])->toBeTrue()
        ->and($this->tenant->fresh()->status)->toBe(TenantStatus::Trial)
        ->and($this->tenant->fresh()->isUsable())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'trial_expired')->exists())->toBeFalse();

    // The other order: the sweep commits first; the extension waits, then re-opens the ended trial.
    Tenant::query()->whereKey($this->tenant->id)->update(['trial_ends_at' => now()->subMinute()]);

    $race = Race::run(
        holder: fn () => app(TenantLifecycleService::class)->sweep(),
        contender: fn () => app(TenantLifecycleService::class)->extendTrial(Tenant::query()->findOrFail($this->tenant->id), 7, 'Extended on request'),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['completed'])->toBeTrue()
        ->and($this->tenant->fresh()->status)->toBe(TenantStatus::Trial)
        ->and($this->tenant->fresh()->trial_ends_at->isFuture())->toBeTrue();
});

test('two provisionings of the same tenant at once create one tenant, one plan and one invitation', function (): void {
    $slug = 'prov-'.Str::lower(Str::random(8));
    $request = fn (): ProvisioningRequest => new ProvisioningRequest(slug: $slug, name: 'Raced Org', ownerEmail: "owner.{$slug}@commercial-race.test", planCode: 'starter', trialDays: 14);

    $race = Race::run(
        holder: fn () => app(TenantProvisioningService::class)->provision($request()),
        contender: fn () => app(TenantProvisioningService::class)->provision($request()),
    );

    $tenant = Tenant::query()->where('slug', $slug)->sole();

    expect($race['blocked'])->toBeTrue()
        ->and($race['completed'])->toBeTrue()
        ->and($tenant->status)->toBe(TenantStatus::Trial)
        ->and(TenantContext::current()->run($tenant, fn () => [TenantPlanAssignment::query()->count(), TenantInvitation::query()->count(), Role::query()->forCurrentTenant()->where('key', 'chro')->count()]))->toBe([1, 1, 1]);
});

test('two retries of a failed provisioning finish it once', function (): void {
    $slug = 'retry-'.Str::lower(Str::random(8));
    $request = fn (): ProvisioningRequest => new ProvisioningRequest(slug: $slug, name: 'Retried Org', ownerEmail: "owner.{$slug}@commercial-race.test", planCode: 'starter');
    app()->instance(TenantDefaults::class, new class extends TenantDefaults
    {
        public function apply(): void
        {
            throw new RuntimeException('Reference data unavailable');
        }
    });

    expect(fn () => app(TenantProvisioningService::class)->provision($request()))->toThrow(RuntimeException::class);
    app()->forgetInstance(TenantDefaults::class);
    app()->forgetInstance(TenantProvisioningService::class);

    $race = Race::run(
        holder: fn () => app(TenantProvisioningService::class)->provision($request()),
        contender: fn () => app(TenantProvisioningService::class)->provision($request()),
    );

    $tenant = Tenant::query()->where('slug', $slug)->sole();

    expect($race['blocked'])->toBeTrue()
        ->and($race['completed'])->toBeTrue()
        ->and($tenant->status)->toBe(TenantStatus::Active)
        ->and($tenant->provisioning_error)->toBeNull()
        ->and(TenantContext::current()->run($tenant, fn () => [TenantPlanAssignment::query()->count(), TenantInvitation::query()->count(), AuditLog::query()->where('action', 'tenant_provisioned')->count()]))->toBe([1, 1, 1]);
});

test('with a shared cache, a decision cached during a commercial change is never served after it', function (): void {
    config(['cache.default' => 'database']);
    Cache::forgetDriver('database');
    $before = $this->tenant->fresh()->entitlement_version;

    $race = Race::run(
        holder: fn () => app(EntitlementOverrideService::class)->set($this->tenant->fresh(), Entitlement::ExportsData, false, 'Exports off'),
        contender: function (): void {
            // Another worker, on its own connection, decides while the change is uncommitted: it
            // sees (and caches) the committed state, under the version it read.
            Cache::forgetDriver('database');
            app(EntitlementService::class)->forget();

            if (! app(EntitlementService::class)->allows(Entitlement::ExportsData, Tenant::query()->findOrFail(TenantContext::current()->requireId()))) {
                throw new RuntimeException('Saw an uncommitted change.');
            }
        },
    );

    app(EntitlementService::class)->forget();
    $after = $this->tenant->fresh();

    expect($race['completed'])->toBeTrue()
        ->and(Cache::has(TenantCache::key('entitlements:v'.$before, $this->tenant->id)))->toBeTrue()
        ->and($after->entitlement_version)->toBeGreaterThan($before)
        ->and(app(EntitlementService::class)->allows(Entitlement::ExportsData, $after))->toBeFalse();

    Cache::flush();
});
