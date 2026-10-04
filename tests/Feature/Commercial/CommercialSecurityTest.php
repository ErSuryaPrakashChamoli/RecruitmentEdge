<?php

use App\Enums\DistributionStatus;
use App\Enums\Entitlement;
use App\Enums\RequisitionStatus;
use App\Filament\Pages\PlanAndUsage;
use App\Filament\Pages\RecruitmentReports;
use App\Jobs\PublishJobDistributionJob;
use App\Models\JobDistribution;
use App\Models\JobPosting;
use App\Models\RecruitmentRequisition;
use App\Models\Tenant;
use App\Models\TenantEntitlementOverride;
use App\Models\TenantPlanAssignment;
use App\Models\User;
use App\Services\Distribution\JobBoardRegistry;
use App\Services\Distribution\JobDistributionService;
use App\Services\Entitlements\EntitlementDenied;
use App\Services\Entitlements\EntitlementService;
use App\Services\Platform\Commercial\EntitlementOverrideService;
use App\Services\Platform\Commercial\PlanCatalog;
use App\Services\Tenancy\TenantCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Feature\Commercial\CommercialWorld;

/*
 * SaaS-3 security: one tenant never sees or changes another's plan, usage or overrides; the
 * commercial control plane has no tenant-facing door; and a denial holds on every path — HTTP,
 * Livewire, a queued job, a stale cache — not only where the button was hidden.
 */
beforeEach(function (): void {
    $this->world = CommercialWorld::build();
});

test('the Plan & usage page shows the organisation\'s own plan and usage — never another\'s', function (): void {
    app(EntitlementOverrideService::class)->set($this->world->tenant('growth'), Entitlement::MembersActiveMax, 99, 'Bravo only');
    $this->actInTenant($this->world->tenant('trialStarter'));
    $this->actingAs($this->world->admins['trialStarter']->fresh());

    $this->get('/admin/alpha-trial/plan-and-usage')
        ->assertOk()
        ->assertSee('Starter')
        ->assertSee('Trial')
        ->assertDontSee('Growth');

    $seats = collect(Livewire::test(PlanAndUsage::class)->instance()->summary()['lines'])->firstWhere('label', Entitlement::MembersActiveMax->label());

    expect($seats['granted'])->toBe('5')
        ->and($seats['usage'])->toBe(1);

    $this->get('/admin/bravo-growth/plan-and-usage')->assertNotFound();
});

test('the Plan & usage page needs an administrator\'s permission, and offers no way to change the plan', function (): void {
    $this->actInTenant($this->world->tenant('growth'));
    $recruiter = $this->world->in('growth', fn () => User::factory()->create()->assignRole('recruiter'));

    $this->actingAs($recruiter->fresh())->get('/admin/bravo-growth/plan-and-usage')->assertForbidden();

    $this->actingAs($this->world->admins['growth']->fresh());
    $page = Livewire::test(PlanAndUsage::class)->assertOk();

    expect($page->instance()->getCachedHeaderActions())->toBeEmpty();
});

test('no tenant route reaches the commercial control plane', function (): void {
    $tenantRoutes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route): bool => str_starts_with($route->uri(), 'admin/') || str_starts_with($route->uri(), 'portal') || str_starts_with($route->uri(), 'careers'));
    $commercial = $tenantRoutes->filter(fn ($route): bool => str_contains((string) $route->getActionName(), 'Platform\\Commercial'));

    expect($commercial)->toBeEmpty()
        ->and($tenantRoutes->map->uri()->filter(fn (string $uri): bool => str_contains($uri, 'entitlement') || str_contains($uri, 'provision')))->toBeEmpty();
});

test('plan assignments and overrides are tenant records: another tenant\'s never appear', function (): void {
    app(EntitlementOverrideService::class)->set($this->world->tenant('growth'), Entitlement::AiAssistant, false, 'Bravo only');

    expect($this->world->in('trialStarter', fn () => TenantEntitlementOverride::query()->count()))->toBe(0)
        ->and($this->world->in('trialStarter', fn () => TenantPlanAssignment::query()->pluck('tenant_id')->unique()->all()))->toBe([$this->world->tenants['trialStarter']->id])
        ->and($this->world->in('growth', fn () => TenantEntitlementOverride::query()->count()))->toBe(1);
});

test('a direct Livewire call to a hidden export is refused', function (): void {
    app(EntitlementOverrideService::class)->set($this->world->tenant('growth'), Entitlement::ExportsData, false, 'Exports off');
    $this->actInTenant($this->world->tenant('growth'));
    $this->actingAs($this->world->admins['growth']->fresh());

    Livewire::test(RecruitmentReports::class)->call('exportFunnel')->assertForbidden();
});

test('a job-board publish already queued is refused at run time once the plan no longer includes it', function (): void {
    Queue::fake();
    $posting = $this->world->in('growth', fn () => JobPosting::factory()->create(['requisition_id' => RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open])->id]));
    $distribution = $this->world->in('growth', fn () => app(JobDistributionService::class)->publish($posting, ['naukri'])->sole());

    app(EntitlementOverrideService::class)->set($this->world->tenant('growth'), Entitlement::DistributionJobBoards, false, 'Boards off');
    $this->world->in('growth', fn () => (new PublishJobDistributionJob($distribution->id, 'publish'))->handle(app(JobBoardRegistry::class), app(JobDistributionService::class)));

    $row = $this->world->in('growth', fn () => JobDistribution::query()->findOrFail($distribution->id));

    expect($row->status)->toBe(DistributionStatus::Failed)
        ->and($row->last_error)->toContain('not included')
        ->and($row->external_id)->toBeNull();
});

test('a decision cached before a commercial change is never served after it', function (): void {
    $growth = $this->world->tenant('growth');
    expect($this->world->in('growth', fn () => app(EntitlementService::class)->allows(Entitlement::ExportsData)))->toBeTrue();

    // Another process (a platform console) turns exports off; this process still holds the old entry.
    app(EntitlementOverrideService::class)->set($growth, Entitlement::ExportsData, false, 'Exports off');
    app()->forgetScopedInstances();

    expect($this->world->in('growth', fn () => app(EntitlementService::class)->allows(Entitlement::ExportsData)))->toBeFalse()
        ->and(fn () => $this->world->in('growth', fn () => app(EntitlementService::class)->require(Entitlement::ExportsData)))->toThrow(EntitlementDenied::class);
});

test('a cache entry for one tenant can never answer for another, even at the same entitlement version', function (): void {
    Tenant::query()->whereKey([$this->world->tenants['enterprise']->id, $this->world->tenants['trialStarter']->id])->update(['entitlement_version' => 41]);
    Cache::flush();

    expect($this->world->in('enterprise', fn () => app(EntitlementService::class)->allows(Entitlement::AiAssistant)))->toBeTrue()
        ->and(Cache::has(TenantCache::key('entitlements:v41', $this->world->tenants['enterprise']->id)))->toBeTrue()
        ->and(Cache::has(TenantCache::key('entitlements:v41', $this->world->tenants['trialStarter']->id)))->toBeFalse();

    app()->forgetScopedInstances();

    expect($this->world->in('trialStarter', fn () => app(EntitlementService::class)->allows(Entitlement::AiAssistant)))->toBeFalse();
});

test('a suspended tenant cannot have entitled work done, whoever asks', function (): void {
    $service = app(EntitlementService::class);

    expect($this->world->in('suspended', fn () => $service->effective(Entitlement::ExportsData)->source))->toBe('tenant_inactive')
        ->and(fn () => $this->world->in('suspended', fn () => $service->require(Entitlement::ExportsData)))->toThrow(EntitlementDenied::class, 'not active')
        ->and(fn () => $this->world->in('suspended', fn () => $service->consume(Entitlement::RequisitionsActiveMax, fn () => RecruitmentRequisition::factory()->create())))->toThrow(EntitlementDenied::class)
        ->and($this->world->in('suspended', fn () => RecruitmentRequisition::query()->count()))->toBe(0);
});

test('an override cannot be invented: wrong type, a past end, or no reason are refused', function (): void {
    $growth = $this->world->tenant('growth');
    $overrides = app(EntitlementOverrideService::class);

    expect(fn () => $overrides->set($growth, Entitlement::MembersActiveMax, -1, 'Negative'))->toThrow(DomainException::class)
        ->and(fn () => $overrides->set($growth, Entitlement::MembersActiveMax, 'lots', 'String'))->toThrow(DomainException::class)
        ->and(fn () => $overrides->set($growth, Entitlement::MembersActiveMax, 30, 'Expired', now()->subDay()))->toThrow(DomainException::class, 'future')
        ->and($overrides->set($growth, Entitlement::MembersActiveMax, PlanCatalog::UNLIMITED, 'Pilot')->is_unlimited)->toBeTrue()
        ->and($this->world->in('growth', fn () => TenantEntitlementOverride::query()->where('is_current', true)->count()))->toBe(1);
});

test('the trial notice is shown only to a tenant on a running trial', function (): void {
    $this->actInTenant($this->world->tenant('trialStarter'));
    $this->actingAs($this->world->admins['trialStarter']->fresh());
    $this->get('/admin/alpha-trial')->assertOk()->assertSee('Trial — ends on');

    $this->actInTenant($this->world->tenant('growth'));
    $this->actingAs($this->world->admins['growth']->fresh());
    $this->get('/admin/bravo-growth')->assertOk()->assertDontSee('Trial — ends on');
});
