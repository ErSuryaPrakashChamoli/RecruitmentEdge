<?php

use App\Enums\Entitlement;
use App\Enums\RequisitionStatus;
use App\Filament\Pages\PlanAndUsage;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\RequisitionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Commercial\CommercialWorld;

/*
 * SaaS-3: entitlement decisions are cheap — one map per tenant and entitlement version (cached),
 * memoised for the request; usage is one aggregate per limit; nothing grows with the data.
 */
beforeEach(function (): void {
    $this->world = CommercialWorld::build();
});

/**
 * @return list<string>
 */
function commercialQueries(Closure $work): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $work();
    $queries = array_map(fn (array $query): string => $query['query'], DB::getQueryLog());
    DB::disableQueryLog();

    return $queries;
}

function commercialPlanQueries(array $queries): int
{
    return count(array_filter($queries, fn (string $sql): bool => (bool) preg_match('/plan_entitlements|tenant_plan_assignments|tenant_entitlement_overrides/', $sql)));
}

test('a panel request reads the plan at most once, and not at all once it is cached', function (): void {
    $this->actInTenant($this->world->tenant('growth'));
    $this->actingAs($this->world->admins['growth']->fresh());
    app(EntitlementService::class)->forget();
    Cache::flush();

    $cold = commercialQueries(fn () => $this->get('/admin/bravo-growth/candidates')->assertOk());
    app(EntitlementService::class)->forget();
    $warm = commercialQueries(fn () => $this->get('/admin/bravo-growth/candidates')->assertOk());

    // One map load: the current assignment, its version's entitlements, the overrides.
    expect(commercialPlanQueries($cold))->toBe(3)
        ->and(commercialPlanQueries($warm))->toBe(0);
});

test('the Plan & usage page costs the same with 2 records or 40', function (): void {
    $this->actInTenant($this->world->tenant('growth'));
    $this->actingAs($this->world->admins['growth']->fresh());
    $render = fn (): int => count(commercialQueries(fn () => Livewire::test(PlanAndUsage::class)->assertOk()));

    $this->world->in('growth', fn () => RecruitmentRequisition::factory()->count(2)->create(['status' => RequisitionStatus::Open]));
    // Each measurement follows a warm-up render: the permission cache rebuilt after new role
    // assignments is not the page's cost.
    $render();
    $few = $render();

    $this->world->in('growth', function (): void {
        RecruitmentRequisition::factory()->count(38)->create(['status' => RequisitionStatus::Open]);
        User::factory()->count(10)->create()->each->assignRole('recruiter');
    });
    $render();
    $many = $render();

    expect($many)->toBe($few);
});

test('an atomic limit check is a constant handful of queries, whatever the usage', function (): void {
    $admin = $this->world->admins['growth']->fresh();
    $create = fn (): int => count($this->world->in('growth', fn () => commercialQueries(fn () => app(RequisitionService::class)->create($admin, fn () => RecruitmentRequisition::factory()->create()))));

    // Warm-up: the actor's permissions load once per request, not per check.
    $create();
    $first = $create();
    $this->world->in('growth', fn () => RecruitmentRequisition::factory()->count(30)->create(['status' => RequisitionStatus::Open]));
    $later = $create();

    expect($later)->toBe($first)
        ->and($this->world->in('growth', fn () => app(EntitlementService::class)->usage(Entitlement::RequisitionsActiveMax)))->toBe(33);
});
