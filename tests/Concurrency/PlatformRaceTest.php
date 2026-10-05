<?php

use App\Enums\DeletionRequestStatus;
use App\Enums\PlatformRole;
use App\Enums\SupportGrantStatus;
use App\Enums\SupportScope;
use App\Enums\TenantStatus;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\SupportAccessGrant;
use App\Models\Tenant;
use App\Models\TenantDeletionRequest;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Platform\PlatformAccess;
use App\Services\Platform\SupportAccessService;
use App\Services\Platform\TenantAdministrationService;
use App\Services\Platform\TenantDeletionService;
use App\Services\Platform\TenantOwnershipService;
use App\Services\Platform\TenantPurgeService;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concurrency\Race;

/*
 * SaaS-5: platform control under real two-transaction concurrency (MySQL 8.4, REPEATABLE READ).
 * Every platform writer takes the tenant row lock first, then its own record (grant, deletion
 * request); the contender blocks on that lock — not on anything earlier — and decides on the
 * committed outcome. vendor/bin/pest -c phpunit.concurrency.xml
 */
beforeEach(function (): void {
    Race::prepareDatabase();
    Storage::fake('local');
    Storage::fake('public');
    Mail::fake();

    $this->administrator = platformRaceOperator(PlatformRole::Administrator);
    $this->secondAdministrator = platformRaceOperator(PlatformRole::Administrator);
    $this->support = platformRaceOperator(PlatformRole::Support);
    $this->target = Tenant::factory()->create(['slug' => 'plat-'.Str::lower(Str::random(10))]);

    TenantContext::current()->run($this->target, function (): void {
        (new RolePermissionSeeder)->run();
        $this->owner = User::factory()->create(['email' => 'owner.'.Str::random(8).'@platform-race.test'])->assignRole('chro');
        $this->heirA = User::factory()->create(['email' => 'heir-a.'.Str::random(8).'@platform-race.test'])->assignRole('chro');
        $this->heirB = User::factory()->create(['email' => 'heir-b.'.Str::random(8).'@platform-race.test'])->assignRole('chro');
    });
    TenantMembership::query()->where('tenant_id', $this->target->id)->where('user_id', $this->owner->id)->update(['is_owner' => true]);
});

afterEach(function (): void {
    Carbon::setTestNow();
    TenantContext::current()->setTenant(Tenant::query()->where('slug', 'concurrency')->firstOrFail());
});

function platformRaceOperator(PlatformRole $role): User
{
    $user = TenantContext::current()->runWithoutTenant(fn () => User::factory()->create(['email' => 'op.'.Str::lower(Str::random(10)).'@platform-race.test']));
    app(PlatformAccess::class)->grant($user, $role, 'Race rota');

    return $user;
}

function platformRaceFresh(Tenant $tenant): Tenant
{
    return Tenant::query()->findOrFail($tenant->id);
}

function platformRaceOwners(Tenant $tenant): array
{
    return TenantMembership::query()->where('tenant_id', $tenant->id)->where('is_owner', true)->pluck('user_id')->all();
}

/**
 * Which table the contender is waiting on (performance_schema), read while it is blocked.
 */
function platformRaceWaitingOn(): array
{
    return collect(DB::select("SELECT DISTINCT OBJECT_NAME AS object FROM performance_schema.data_locks WHERE LOCK_STATUS = 'WAITING' AND OBJECT_SCHEMA = DATABASE()"))->pluck('object')->all();
}

function platformRaceGrant(object $test): SupportAccessGrant
{
    return app(SupportAccessService::class)->request(platformRaceFresh($test->target), [SupportScope::Diagnostics], 60, 'Ticket', $test->support);
}

function platformRaceCancelledWithRequest(object $test, bool $approved = false, bool $due = false): TenantDeletionRequest
{
    app(TenantAdministrationService::class)->cancel(platformRaceFresh($test->target), 'Customer left', $test->administrator);
    TenantContext::current()->run($test->target, fn () => Candidate::factory()->count(3)->create());
    $request = app(TenantDeletionService::class)->request(platformRaceFresh($test->target), 'Contract ended', $test->administrator);

    if ($approved) {
        $request = app(TenantDeletionService::class)->approve($request, $test->secondAdministrator);
    }

    if ($due) {
        TenantDeletionRequest::query()->whereKey($request->id)->update(['purge_after' => now()->subMinute()]);
    }

    return $request->fresh();
}

test('transfer vs transfer: the second, decided on the owner it saw, is refused once the first commits', function (): void {
    $race = Race::run(
        holder: fn () => app(TenantOwnershipService::class)->transfer(platformRaceFresh($this->target), $this->heirA, 'Handover', $this->administrator, $this->owner),
        contender: fn () => app(TenantOwnershipService::class)->transfer(platformRaceFresh($this->target), User::query()->findOrFail($this->heirB->id), 'Handover', User::query()->findOrFail($this->secondAdministrator->id), User::query()->findOrFail($this->owner->id)),
        whileBlocked: fn () => platformRaceWaitingOn(),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['observed'])->toBe(['tenants'])
        ->and($race['message'])->toContain('owner changed meanwhile')
        ->and(platformRaceOwners($this->target))->toBe([$this->heirA->id]);
});

test('audit ordering: a transfer decided after another commits records the committed owner as its previous owner', function (): void {
    $race = Race::run(
        holder: fn () => app(TenantOwnershipService::class)->transfer(platformRaceFresh($this->target), $this->heirA, 'First', $this->administrator),
        contender: fn () => app(TenantOwnershipService::class)->transfer(platformRaceFresh($this->target), User::query()->findOrFail($this->heirB->id), 'Second', User::query()->findOrFail($this->secondAdministrator->id)),
        whileBlocked: fn () => platformRaceWaitingOn(),
    );

    $entries = TenantContext::current()->run($this->target, fn () => AuditLog::query()->where('action', 'ownership_transferred')->orderBy('id')->get());

    expect($race['blocked'])->toBeTrue()
        ->and($race['observed'])->toBe(['tenants'])
        ->and($race['completed'])->toBeTrue()
        ->and($entries->map(fn (AuditLog $entry): array => [$entry->old_values['owner_user_id'], $entry->changes['owner_user_id'], $entry->user_id])->all())
        ->toBe([[$this->owner->id, $this->heirA->id, $this->administrator->id], [$this->heirA->id, $this->heirB->id, $this->secondAdministrator->id]])
        ->and(platformRaceOwners($this->target))->toBe([$this->heirB->id]);
});

test('grant vs revocation: an approval waiting on a revocation finds it revoked and never activates it', function (): void {
    $grant = platformRaceGrant($this);
    $admin = $this->owner;

    $race = Race::run(
        holder: fn () => TenantContext::current()->run($this->target, fn () => app(SupportAccessService::class)->revoke($grant, $admin, 'Changed our mind')),
        contender: fn () => TenantContext::current()->run(platformRaceFresh($this->target), fn () => app(SupportAccessService::class)->approve($grant, [SupportScope::Diagnostics], 30, User::query()->findOrFail($admin->id))),
        whileBlocked: fn () => platformRaceWaitingOn(),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['observed'])->toBe(['tenants'])
        ->and($race['message'])->toContain('revoked')
        ->and(SupportAccessGrant::query()->withoutTenancy()->find($grant->id)->status)->toBe(SupportGrantStatus::Revoked);
});

test('use vs expiry: a use checked before the end waits on the expiry being recorded and is refused', function (): void {
    $grant = TenantContext::current()->run($this->target, fn () => app(SupportAccessService::class)->approve(platformRaceGrant($this), [SupportScope::Diagnostics], 30, $this->owner));
    SupportAccessGrant::query()->withoutTenancy()->whereKey($grant->id)->update(['expires_at' => now()->subSecond()]);
    $support = $this->support;

    $race = Race::run(
        holder: fn () => app(SupportAccessService::class)->expireDue(),
        // A request that began just before the end (its clock), racing the sweep that recorded it.
        contender: function () use ($grant, $support): void {
            Carbon::setTestNow(CarbonImmutable::now()->subMinute());
            app(SupportAccessService::class)->open($grant->id, SupportScope::Diagnostics, User::query()->findOrFail($support->id), 'workspace:diagnostics');
        },
        whileBlocked: fn () => platformRaceWaitingOn(),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['observed'])->toBe(['support_access_grants'])
        ->and($race['message'])->toContain('not active')
        ->and(SupportAccessGrant::query()->withoutTenancy()->find($grant->id))->status->toBe(SupportGrantStatus::Expired)->use_count->toBe(0);
});

test('deletion request vs cancellation: an approval waiting on the cancellation is refused; the tenant stays cancelled', function (): void {
    $request = platformRaceCancelledWithRequest($this);

    $race = Race::run(
        holder: fn () => app(TenantDeletionService::class)->cancel($request, 'Customer renewed', $this->administrator),
        contender: fn () => app(TenantDeletionService::class)->approve(TenantDeletionRequest::query()->findOrFail($request->id), User::query()->findOrFail($this->secondAdministrator->id)),
        whileBlocked: fn () => platformRaceWaitingOn(),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['observed'])->toBe(['tenants'])
        ->and($race['message'])->toContain('cancelled')
        ->and($request->fresh()->status)->toBe(DeletionRequestStatus::Cancelled)
        ->and(platformRaceFresh($this->target)->status)->toBe(TenantStatus::Cancelled);
});

test('deletion vs purge: a purge waiting on a cancellation purges nothing', function (): void {
    $request = platformRaceCancelledWithRequest($this, approved: true, due: true);

    $race = Race::run(
        holder: fn () => app(TenantDeletionService::class)->cancel($request, 'Customer renewed', $this->administrator),
        contender: function () use ($request): void {
            $outcome = app(TenantPurgeService::class)->purge($request->id, 'worker-b');

            throw new RuntimeException("purge: {$outcome}");
        },
        whileBlocked: fn () => platformRaceWaitingOn(),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['observed'])->toBe(['tenants'])
        ->and($race['message'])->toBe('purge: nothing to purge (cancelled)')
        ->and(DB::table('candidates')->where('tenant_id', $this->target->id)->count())->toBe(3)
        ->and(platformRaceFresh($this->target)->status)->toBe(TenantStatus::Cancelled);
});

test('duplicate purge workers: the second waits on the first and finds the tenant purged', function (): void {
    $request = platformRaceCancelledWithRequest($this, approved: true, due: true);

    $race = Race::run(
        holder: fn () => app(TenantPurgeService::class)->purge($request->id, 'worker-a'),
        contender: function () use ($request): void {
            $outcome = app(TenantPurgeService::class)->purge($request->id, 'worker-b');

            throw new RuntimeException("purge: {$outcome}");
        },
        whileBlocked: fn () => platformRaceWaitingOn(),
    );

    $request->refresh();

    expect($race['blocked'])->toBeTrue()
        ->and($race['observed'])->toBe(['tenants'])
        ->and($race['message'])->toBe('purge: nothing to purge (purged)')
        ->and($request->status)->toBe(DeletionRequestStatus::Purged)
        ->and($request->attempts)->toBe(1)
        ->and(TenantContext::current()->run($this->target, fn () => AuditLog::query()->where('action', 'tenant_purge_started')->count()))->toBe(1)
        ->and(platformRaceFresh($this->target)->status)->toBe(TenantStatus::Deleted);
});

test('purge retry: two workers resuming a stopped purge — one resumes, the other finds it done', function (): void {
    $request = platformRaceCancelledWithRequest($this, approved: true, due: true);
    TenantDeletionRequest::query()->whereKey($request->id)->update(['status' => DeletionRequestStatus::Purging->value, 'lease_owner' => 'crashed', 'lease_until' => now()->subMinute(), 'attempts' => 1]);

    $race = Race::run(
        holder: fn () => app(TenantPurgeService::class)->purge($request->id, 'worker-a'),
        contender: function () use ($request): void {
            $outcome = app(TenantPurgeService::class)->purge($request->id, 'worker-b');

            throw new RuntimeException("purge: {$outcome}");
        },
        whileBlocked: fn () => platformRaceWaitingOn(),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['observed'])->toBe(['tenants'])
        ->and($race['message'])->toBe('purge: nothing to purge (purged)')
        ->and($request->fresh()->attempts)->toBe(2)
        ->and(TenantContext::current()->run($this->target, fn () => AuditLog::query()->where('action', 'tenant_purge_resumed')->count()))->toBe(1);
});

test('platform lifecycle vs tenant action: an ownership transfer waiting on a cancellation is refused', function (): void {
    $race = Race::run(
        holder: fn () => app(TenantAdministrationService::class)->cancel(platformRaceFresh($this->target), 'Customer left', $this->administrator),
        contender: fn () => app(TenantOwnershipService::class)->transfer(platformRaceFresh($this->target), User::query()->findOrFail($this->heirA->id), 'Handover', User::query()->findOrFail($this->secondAdministrator->id)),
        whileBlocked: fn () => platformRaceWaitingOn(),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['observed'])->toBe(['tenants'])
        ->and($race['message'])->toContain('cannot change')
        ->and(platformRaceOwners($this->target))->toBe([$this->owner->id]);
});

test('platform lifecycle vs tenant action: a support approval waiting on a suspension is refused', function (): void {
    $grant = platformRaceGrant($this);
    $admin = $this->owner;

    $race = Race::run(
        holder: fn () => app(TenantAdministrationService::class)->suspend(platformRaceFresh($this->target), 'Abuse report', $this->administrator),
        contender: fn () => TenantContext::current()->run(platformRaceFresh($this->target), fn () => app(SupportAccessService::class)->approve($grant, [SupportScope::Diagnostics], 30, User::query()->findOrFail($admin->id))),
        whileBlocked: fn () => platformRaceWaitingOn(),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['observed'])->toBe(['tenants'])
        ->and($race['message'])->toContain('not active')
        ->and(SupportAccessGrant::query()->withoutTenancy()->find($grant->id)->status)->toBe(SupportGrantStatus::Requested);
});
