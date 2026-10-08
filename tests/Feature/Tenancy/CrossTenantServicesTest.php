<?php

use App\Enums\JoiningStatus;
use App\Filament\Resources\Candidates\Schemas\CandidatePicker;
use App\Models\AutomationRule;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\RecruitmentSetting;
use App\Models\Role;
use App\Models\Tenant;
use App\Services\Automation\AutomationEngine;
use App\Services\CandidateDuplicateDetector;
use App\Services\HierarchyService;
use App\Services\Metrics\MetricPeriod;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricService;
use App\Services\PlatformAlertService;
use App\Services\RecruiterActionService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\Tenancy\TenantWorld;

use function Pest\Laravel\actingAs;

/*
 * SaaS-1: the services every page, job and tool calls — hierarchy ("view all"), governed metrics
 * and their cache, settings and their cache, search and duplicate detection, alert routing and
 * automation — answer for the current tenant only, even for a CHRO.
 */
beforeEach(function (): void {
    config(['metrics.cache_ttl' => 600]);
    $this->alpha = TenantWorld::build(Tenant::factory()->create(['slug' => 'alpha']), 'ALPHA');
    $this->bravo = TenantWorld::build(Tenant::factory()->create(['slug' => 'bravo']), 'BRAVO');
    $this->actInTenant($this->alpha->tenant);
    actingAs($this->alpha->chro);
});

test('"view all" is all of the current tenant: the hierarchy never reaches another tenant', function (): void {
    $hierarchy = app(HierarchyService::class);

    expect($hierarchy->visibleEmployeeIdsFor($this->alpha->chro))->toBeNull()
        ->and(Employee::query()->pluck('id')->all())->toEqualCanonicalizing([$this->alpha->chroEmployee->id, $this->alpha->recruiterEmployee->id])
        ->and($hierarchy->descendantIdsOf($this->bravo->chroEmployee->id)->all())->toBe([])
        ->and($hierarchy->canView($this->alpha->chro, $this->bravo->recruiterEmployee))->toBeFalse()
        ->and($hierarchy->descendantIdsOf($this->alpha->chroEmployee->id)->sort()->values()->all())->toBe([$this->alpha->chroEmployee->id, $this->alpha->recruiterEmployee->id]);
});

test('governed metrics count the tenant\'s own records, and two tenants\' view-all results are never cached together', function (): void {
    $hire = fn () => lifecycleFixture(fn () => CandidateJoining::factory()->create([
        'candidate_application_id' => CandidateApplication::factory()->create()->id,
        'status' => JoiningStatus::Joined,
        'actual_doj' => now()->toDateString(),
    ]));
    TenantContext::current()->run($this->alpha->tenant, $hire);
    TenantContext::current()->run($this->bravo->tenant, function () use ($hire): void {
        $hire();
        $hire();
    });

    $hires = fn (Tenant $tenant, $viewer) => TenantContext::current()->run($tenant, fn () => app(MetricService::class)->get('hiring.hires', MetricQuery::make(MetricPeriod::lastDays(7), $viewer))->value);

    expect($hires($this->alpha->tenant, $this->alpha->chro))->toBe(1.0)
        ->and($hires($this->bravo->tenant, $this->bravo->chro))->toBe(2.0)
        ->and($hires($this->alpha->tenant, $this->alpha->chro))->toBe(1.0)
        ->and($hires($this->alpha->tenant, null))->toBe(1.0)
        ->and($hires($this->bravo->tenant, null))->toBe(2.0);
});

test('settings are per tenant, and so is their cache', function (): void {
    $setting = fn (Tenant $tenant) => TenantContext::current()->run($tenant, fn () => RecruitmentSetting::get('sla_screening_hours'));

    expect($setting($this->alpha->tenant))->toBe(24)
        ->and($setting($this->bravo->tenant))->toBe(72)
        ->and($setting($this->alpha->tenant))->toBe(24);

    TenantContext::current()->run($this->bravo->tenant, fn () => RecruitmentSetting::put('sla_screening_hours', '96', 'int'));

    expect($setting($this->alpha->tenant))->toBe(24)
        ->and($setting($this->bravo->tenant))->toBe(96);
});

test('search, pickers and duplicate detection look inside the tenant only', function (): void {
    $matches = app(CandidateDuplicateDetector::class)->detect(['email' => 'shared.candidate@example.test', 'mobile' => '9876500001']);

    expect($matches->map(fn ($match) => $match->candidate->id)->all())->toBe([$this->alpha->candidate->id])
        ->and(CandidatePicker::selectableCandidates()->pluck('candidates.id')->all())->toBe([$this->alpha->candidate->id]);
});

test('alerts, fallback owners and automation triggers are decided inside the tenant', function (): void {
    TenantContext::current()->run($this->bravo->tenant, fn () => AutomationRule::factory()->active()->on('offer.accepted')->create(['owner_id' => $this->bravo->chro->id]));
    TenantContext::current()->run($this->alpha->tenant, fn () => AutomationRule::factory()->active()->on('interview.scheduled')->create(['owner_id' => $this->alpha->chro->id]));
    app(AutomationEngine::class)->forgetActiveTriggers();

    $triggers = fn (Tenant $tenant) => TenantContext::current()->run($tenant, fn () => app(AutomationEngine::class)->activeTriggers());

    expect($triggers($this->alpha->tenant))->toBe(['interview.scheduled'])
        ->and($triggers($this->bravo->tenant))->toBe(['offer.accepted'])
        ->and(app(PlatformAlertService::class)->recipients()->pluck('id')->all())->toBe([$this->alpha->chro->id])
        ->and(app(RecruiterActionService::class)->fallbackOwner()?->tenant_id)->toBe($this->alpha->tenant->id);
});

test('no ability is granted on another tenant\'s record, even to a CHRO whose policy says "view all"', function (): void {
    $bravoCandidate = TenantContext::current()->run($this->bravo->tenant, fn () => $this->bravo->candidate->fresh());
    $bravoRole = TenantContext::current()->run($this->bravo->tenant, fn () => Role::byKey('recruiter'));

    expect(Gate::forUser($this->alpha->chro)->allows('view', $this->alpha->candidate))->toBeTrue()
        ->and(Gate::forUser($this->alpha->chro)->allows('view', $bravoCandidate))->toBeFalse()
        ->and(Gate::forUser($this->alpha->chro)->allows('update', $bravoCandidate))->toBeFalse()
        ->and(Gate::forUser($this->alpha->chro)->allows('update', $bravoRole))->toBeFalse();
});
