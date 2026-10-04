<?php

use App\Enums\Entitlement;
use App\Models\Tenant;
use App\Models\TenantEntitlementOverride;
use App\Models\TenantPlanAssignment;
use App\Services\Entitlements\EntitlementService;
use App\Services\Platform\Commercial\EntitlementOverrideService;
use App\Services\Platform\Commercial\PlanAssignmentService;
use App\Services\Platform\Commercial\PlanCatalog;
use App\Services\Tenancy\TenantCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Commercial\CommercialWorld;

/*
 * SaaS-3: one authority decides what a tenant may use — plan version, then a tenant override in
 * force, then nothing: a key that is not granted is denied, never unlimited, and a tenant that is
 * not usable is granted nothing.
 */
beforeEach(function (): void {
    $this->world = CommercialWorld::build();
    $this->entitlements = fn (): EntitlementService => app(EntitlementService::class);
});

function evaluationEffective(CommercialWorld $world, string $tenant, Entitlement $entitlement): array
{
    return $world->in($tenant, function () use ($entitlement): array {
        $effective = app(EntitlementService::class)->effective($entitlement);

        return [$effective->enabled, $effective->limit, $effective->unlimited, $effective->source];
    });
}

test('each tenant gets exactly what its pinned plan version grants', function (): void {
    expect(evaluationEffective($this->world, 'trialStarter', Entitlement::AiAssistant))->toBe([false, null, false, 'plan'])
        ->and(evaluationEffective($this->world, 'trialStarter', Entitlement::RequisitionsActiveMax))->toBe([true, 10, false, 'plan'])
        ->and(evaluationEffective($this->world, 'growth', Entitlement::AiAssistant))->toBe([true, null, false, 'plan'])
        ->and(evaluationEffective($this->world, 'growth', Entitlement::MembersActiveMax))->toBe([true, 25, false, 'plan'])
        ->and(evaluationEffective($this->world, 'enterprise', Entitlement::RequisitionsActiveMax))->toBe([true, null, true, 'plan'])
        ->and(app(EntitlementService::class)->effective(Entitlement::MembersActiveMax)->unlimited)->toBeTrue();
});

test('a key the plan never mentions is denied — missing is never unlimited', function (): void {
    expect(evaluationEffective($this->world, 'unentitled', Entitlement::AiAssistant))->toBe([false, null, false, 'missing'])
        ->and(evaluationEffective($this->world, 'unentitled', Entitlement::AutomationRules))->toBe([false, null, false, 'missing'])
        ->and(evaluationEffective($this->world, 'unentitled', Entitlement::ExportsData))->toBe([true, null, false, 'plan']);

    $planless = Tenant::factory()->withoutPlan()->create(['slug' => 'no-plan']);

    foreach (Entitlement::cases() as $entitlement) {
        expect(app(EntitlementService::class)->allows($entitlement, $planless->fresh()))->toBeFalse();
    }
});

test('a limit with neither a number nor an explicit unlimited grants nothing', function (): void {
    $versionId = TenantPlanAssignment::query()->where('is_current', true)->value('plan_version_id');
    DB::table('plan_entitlements')->where('plan_version_id', $versionId)->where('key', Entitlement::RequisitionsActiveMax->value)->update(['is_unlimited' => false, 'limit_value' => null]);
    Tenant::query()->whereKey($this->tenant->id)->increment('entitlement_version');

    expect(app(EntitlementService::class)->effective(Entitlement::RequisitionsActiveMax, $this->tenant->fresh())->enabled)->toBeFalse();
});

test('a suspended tenant, and a trial that has ended, are granted nothing', function (): void {
    expect(evaluationEffective($this->world, 'suspended', Entitlement::ExportsData)[0])->toBeFalse()
        ->and(evaluationEffective($this->world, 'trialStarter', Entitlement::ExportsData)[0])->toBeTrue();

    $this->travel(15)->days();

    expect(evaluationEffective($this->world, 'trialStarter', Entitlement::ExportsData)[0])->toBeFalse()
        ->and($this->world->tenant('trialStarter')->effectiveStatus()->value)->toBe('suspended')
        ->and($this->world->tenant('trialStarter')->status->value)->toBe('trial');
});

test('an override replaces the plan value while in force, then the plan value returns', function (): void {
    $growth = $this->world->tenant('growth');
    app(EntitlementOverrideService::class)->set($growth, Entitlement::MembersActiveMax, 30, 'Pilot: five more seats', now()->addDays(7));

    expect(evaluationEffective($this->world, 'growth', Entitlement::MembersActiveMax))->toBe([true, 30, false, 'override']);

    $this->travel(8)->days();

    expect(evaluationEffective($this->world, 'growth', Entitlement::MembersActiveMax))->toBe([true, 25, false, 'plan']);
});

test('an override can switch a feature on or off, and removing it restores the plan', function (): void {
    $starter = $this->world->tenant('trialStarter');
    app(EntitlementOverrideService::class)->set($starter, Entitlement::AiAssistant, true, 'AI pilot');

    expect(evaluationEffective($this->world, 'trialStarter', Entitlement::AiAssistant))->toBe([true, null, false, 'override']);

    app(EntitlementOverrideService::class)->remove($this->world->tenant('trialStarter'), Entitlement::AiAssistant, 'Pilot over');

    expect(evaluationEffective($this->world, 'trialStarter', Entitlement::AiAssistant))->toBe([false, null, false, 'plan'])
        ->and(fn () => app(EntitlementOverrideService::class)->set($starter, Entitlement::MembersActiveMax, true, 'wrong type'))->toThrow(DomainException::class)
        ->and(fn () => app(EntitlementOverrideService::class)->set($starter, Entitlement::AiAssistant, 5, 'wrong type'))->toThrow(DomainException::class)
        ->and(fn () => app(EntitlementOverrideService::class)->set($starter, Entitlement::AiAssistant, true, ' '))->toThrow(DomainException::class, 'reason');
});

test('the cache is keyed by tenant and entitlement version, and a commercial change is never answered from the old entry', function (): void {
    $growth = $this->world->tenant('growth');
    $this->world->in('growth', fn () => app(EntitlementService::class)->allows(Entitlement::AiAssistant));

    $oldKey = TenantCache::key('entitlements:v'.$growth->entitlement_version, $growth->id);

    expect(Cache::has($oldKey))->toBeTrue()
        ->and($oldKey)->toStartWith("t:{$growth->id}:");

    app(PlanAssignmentService::class)->assign($growth, app(PlanAssignmentService::class)->latestVersion('starter'), 'platform', 'Downgrade');

    $fresh = $this->world->tenant('growth');

    expect($fresh->entitlement_version)->toBeGreaterThan($growth->entitlement_version)
        ->and($this->world->in('growth', fn () => app(EntitlementService::class)->allows(Entitlement::AiAssistant)))->toBeFalse()
        ->and(Cache::has(TenantCache::key('entitlements:v'.$fresh->entitlement_version, $growth->id)))->toBeTrue();
});

test('the overview of one tenant never contains another tenant\'s plan, usage or overrides', function (): void {
    app(EntitlementOverrideService::class)->set($this->world->tenant('growth'), Entitlement::RequisitionsActiveMax, PlanCatalog::UNLIMITED, 'Growth only');

    $starter = collect($this->world->in('trialStarter', fn () => app(EntitlementService::class)->overview()))->keyBy(fn (array $line) => $line['entitlement']->value);

    expect($starter[Entitlement::RequisitionsActiveMax->value]['effective']->limit)->toBe(10)
        ->and($starter[Entitlement::RequisitionsActiveMax->value]['effective']->source)->toBe('plan')
        ->and($this->world->in('trialStarter', fn () => TenantPlanAssignment::query()->count()))->toBe(1)
        ->and($this->world->in('trialStarter', fn () => TenantEntitlementOverride::query()->count()))->toBe(0);
});

test('repeated checks in one request are answered from memory', function (): void {
    $service = app(EntitlementService::class);
    $service->allows(Entitlement::AiAssistant);

    DB::flushQueryLog();
    DB::enableQueryLog();

    foreach (range(1, 20) as $i) {
        $service->allows(Entitlement::AiAssistant);
        $service->effective(Entitlement::RequisitionsActiveMax);
    }

    expect(count(DB::getQueryLog()))->toBe(0);
    DB::disableQueryLog();
});
