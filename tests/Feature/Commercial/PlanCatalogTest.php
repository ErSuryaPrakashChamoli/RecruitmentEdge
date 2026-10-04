<?php

use App\Enums\Entitlement;
use App\Enums\PlanStatus;
use App\Enums\PlanVersionStatus;
use App\Enums\PlatformRole;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\PlanVersion;
use App\Models\TenantPlanAssignment;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Platform\Commercial\EntitlementOverrideService;
use App\Services\Platform\Commercial\PlanAssignmentService;
use App\Services\Platform\Commercial\PlanCatalogService;
use App\Services\Platform\PlatformAccess;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Commercial\CommercialWorld;

/*
 * SaaS-3: the plan catalog is versioned and immutable. Publishing is idempotent and refuses to
 * re-define a published version; tenants are pinned to a version, so a new version changes no one
 * until a platform decision moves them; every assignment is explicit, reasoned and audited.
 */
beforeEach(function (): void {
    $this->world = CommercialWorld::build();
    $this->plans = app(PlanAssignmentService::class);
});

function catalogPlatformAudits(string $action): int
{
    return TenantContext::current()->runWithoutTenant(fn (): int => AuditLog::query()->withoutTenancy()->whereNull('tenant_id')->where('action', $action)->count());
}

/**
 * Publishes Growth v2 (what a new catalog definition would publish): 100 requisitions.
 */
function catalogGrowthV2(): PlanVersion
{
    $version = PlanVersion::query()->create(['plan_id' => Plan::query()->where('code', 'growth')->value('id'), 'version' => 2, 'status' => PlanVersionStatus::Published, 'published_at' => now()]);

    foreach (PlanVersion::query()->whereHas('plan', fn ($plan) => $plan->where('code', 'growth'))->where('version', 1)->sole()->entitlements as $entitlement) {
        $version->entitlements()->create([
            'key' => $entitlement->key,
            'enabled' => $entitlement->enabled,
            'limit_value' => $entitlement->key === Entitlement::RequisitionsActiveMax->value ? 100 : $entitlement->limit_value,
            'is_unlimited' => $entitlement->is_unlimited,
        ]);
    }

    return $version;
}

test('publishing the catalog again changes nothing and records nothing', function (): void {
    $published = catalogPlatformAudits('plan_version_published');
    $rows = [DB::table('plans')->count(), DB::table('plan_versions')->count(), DB::table('plan_entitlements')->count()];

    $result = app(PlanCatalogService::class)->sync();

    expect($result['published'])->toBe([])
        ->and($result['unchanged'])->toContain('starter v1', 'growth v1', 'enterprise v1', 'legacy v1')
        ->and([DB::table('plans')->count(), DB::table('plan_versions')->count(), DB::table('plan_entitlements')->count()])->toBe($rows)
        ->and(catalogPlatformAudits('plan_version_published'))->toBe($published)
        ->and($published)->toBeGreaterThan(0);

    $this->artisan('plans:sync')->expectsOutputToContain('nothing new')->assertSuccessful();
});

test('a published version that no longer matches its definition is refused, never silently rewritten', function (): void {
    $versionId = PlanVersion::query()->whereHas('plan', fn ($plan) => $plan->where('code', 'starter'))->value('id');
    DB::table('plan_entitlements')->where('plan_version_id', $versionId)->where('key', Entitlement::RequisitionsActiveMax->value)->update(['limit_value' => 11]);

    expect(fn () => app(PlanCatalogService::class)->sync())->toThrow(DomainException::class, 'publish v2')
        ->and(DB::table('plan_entitlements')->where('plan_version_id', $versionId)->where('key', Entitlement::RequisitionsActiveMax->value)->value('limit_value'))->toBe(11);

    $this->artisan('plans:sync')->expectsOutputToContain('differs from its definition')->assertFailed();
});

test('a published version and its entitlements cannot be edited or deleted — only retired', function (): void {
    $version = $this->plans->latestVersion('starter');
    $entitlement = $version->entitlements()->first();

    expect(fn () => $version->update(['version' => 9]))->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $version->delete())->toThrow(LogicException::class)
        ->and(fn () => $entitlement->update(['enabled' => ! $entitlement->enabled]))->toThrow(LogicException::class)
        ->and(fn () => PlanEntitlement::query()->whereKey($entitlement->id)->first()->delete())->toThrow(LogicException::class);

    $version->fresh()->update(['status' => PlanVersionStatus::Retired]);

    expect($version->fresh()->status)->toBe(PlanVersionStatus::Retired)
        ->and($version->fresh()->version)->toBe(1);
});

test('a retired version, a retired plan and an internal plan cannot be assigned', function (): void {
    $growth = $this->world->tenant('growth');
    $starter = $this->plans->latestVersion('starter');

    $starter->update(['status' => PlanVersionStatus::Retired]);
    expect(fn () => $this->plans->assign($growth, $starter->fresh(), 'platform', 'Downgrade'))->toThrow(DomainException::class, 'retired');

    Plan::query()->where('code', 'enterprise')->update(['status' => PlanStatus::Retired->value]);
    expect(fn () => $this->plans->assign($growth, $this->plans->latestVersion('enterprise'), 'platform', 'Upgrade'))->toThrow(DomainException::class, 'cannot be assigned')
        ->and(fn () => $this->plans->assign($growth, $this->plans->latestVersion('legacy'), 'platform', 'Legacy'))->toThrow(DomainException::class, 'cannot be assigned')
        ->and(fn () => $this->plans->assign($growth, $this->plans->latestVersion('growth'), 'platform', ' '))->toThrow(DomainException::class, 'reason')
        ->and(fn () => $this->plans->latestVersion('platinum'))->toThrow(DomainException::class, 'No published version')
        ->and($this->world->in('growth', fn () => TenantPlanAssignment::query()->count()))->toBe(1);
});

test('a plan change ends the old assignment, starts the new one and is audited old → new', function (): void {
    $this->plans->assign($this->world->tenant('growth'), $this->plans->latestVersion('enterprise'), 'platform', 'Upgrade agreed');

    $this->world->in('growth', function (): void {
        $history = TenantPlanAssignment::query()->with('planVersion.plan')->orderBy('id')->get();
        $audit = AuditLog::query()->where('action', 'plan_changed')->sole();

        expect($history)->toHaveCount(2)
            ->and($history[0]->is_current)->toBeNull()
            ->and($history[0]->effective_until)->not->toBeNull()
            ->and($history[1]->is_current)->toBeTrue()
            ->and($history[1]->planVersion->plan->code)->toBe('enterprise')
            ->and($audit->old_values)->toBe(['plan' => 'growth', 'version' => 1])
            ->and($audit->changes)->toMatchArray(['plan' => 'enterprise', 'version' => 1, 'source' => 'platform'])
            ->and($audit->reason)->toBe('Upgrade agreed');
    });
});

test('assigning the plan a tenant is already on is a no-op', function (): void {
    $version = $this->world->tenant('growth')->entitlement_version;

    $this->plans->assign($this->world->tenant('growth'), $this->plans->latestVersion('growth'), 'platform', 'Again');

    expect($this->world->tenant('growth')->entitlement_version)->toBe($version)
        ->and($this->world->in('growth', fn () => TenantPlanAssignment::query()->count()))->toBe(1);
});

test('a new plan version changes nobody pinned to the old one until they are moved', function (): void {
    $v2 = catalogGrowthV2();

    expect($this->world->in('growth', fn () => app(EntitlementService::class)->effective(Entitlement::RequisitionsActiveMax)->limit))->toBe(50)
        ->and($this->plans->latestVersion('growth')->is($v2))->toBeTrue();

    $this->plans->assign($this->world->tenant('growth'), $v2, 'platform', 'Moved to v2');

    expect($this->world->in('growth', fn () => app(EntitlementService::class)->effective(Entitlement::RequisitionsActiveMax)->limit))->toBe(100)
        ->and($this->world->in('suspended', fn () => TenantPlanAssignment::query()->where('is_current', true)->sole()->plan_version_id))->not->toBe($v2->id);
});

test('only a platform administrator can publish, assign or override; tenant administrators never can', function (): void {
    $tenantAdmin = $this->world->admins['growth']->fresh();
    $operator = TenantContext::current()->runWithoutTenant(fn () => User::factory()->create(['email' => 'ops@platform.test']));
    app(PlatformAccess::class)->grant($operator, PlatformRole::Administrator, 'Commercial operations');
    $support = TenantContext::current()->runWithoutTenant(fn () => User::factory()->create(['email' => 'support@platform.test']));
    app(PlatformAccess::class)->grant($support, PlatformRole::Support, 'Support rota');
    $growth = $this->world->tenant('growth');

    foreach ([$tenantAdmin, $support->fresh()] as $notAllowed) {
        expect(fn () => app(PlanCatalogService::class)->sync($notAllowed))->toThrow(DomainException::class, 'platform administrator')
            ->and(fn () => $this->plans->assign($growth, $this->plans->latestVersion('enterprise'), 'platform', 'Self-upgrade', $notAllowed))->toThrow(DomainException::class, 'platform administrator')
            ->and(fn () => app(EntitlementOverrideService::class)->set($growth, Entitlement::MembersActiveMax, 999, 'Self-service seats', null, $notAllowed))->toThrow(DomainException::class, 'platform administrator');
    }

    $assignment = $this->plans->assign($growth, $this->plans->latestVersion('enterprise'), 'platform', 'Upgrade agreed', $operator);

    expect($assignment->assigned_by)->toBe($operator->id)
        ->and($this->world->in('growth', fn () => AuditLog::query()->where('action', 'plan_changed')->sole()->changes['by_user_id']))->toBe($operator->id);
});

test('the console assigns a plan by code, refusing an internal plan unless asked', function (): void {
    $this->artisan('tenants:plan', ['slug' => 'bravo-growth', 'plan' => 'legacy', '--reason' => 'Migration'])->expectsOutputToContain('cannot be assigned')->assertFailed();
    $this->artisan('tenants:plan', ['slug' => 'bravo-growth', 'plan' => 'legacy', '--reason' => 'Migration', '--internal' => true])->assertSuccessful();
    $this->artisan('tenants:plan', ['slug' => 'nobody', 'plan' => 'growth', '--reason' => 'x'])->assertFailed();

    expect($this->world->in('growth', fn () => TenantPlanAssignment::query()->where('is_current', true)->sole()->planVersion->plan->code))->toBe('legacy');
});
