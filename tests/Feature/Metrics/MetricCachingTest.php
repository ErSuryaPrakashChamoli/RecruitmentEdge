<?php

use App\Enums\JoiningStatus;
use App\Enums\RequisitionStatus;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\Metrics\MetricPeriod;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricService;
use Database\Seeders\RolePermissionSeeder;

/**
 * Phase 8.5 (D14): a cacheable metric is reused only for the same metric version, viewer scope,
 * period and filters; a real-time metric is never cached.
 */
beforeEach(function (): void {
    config(['metrics.cache_ttl' => 600]);
});

function cachingHire(?Employee $recruiter = null): void
{
    lifecycleFixture(fn () => CandidateJoining::factory()->create([
        'candidate_application_id' => CandidateApplication::factory()->create($recruiter !== null ? ['recruiter_id' => $recruiter->id] : [])->id,
        'status' => JoiningStatus::Joined,
        'actual_doj' => now()->toDateString(),
    ]));
}

test('a cached result is reused for the same viewer, period and filters, within its lifetime', function (): void {
    $query = MetricQuery::make(MetricPeriod::lastDays(7), null);
    cachingHire();

    expect(app(MetricService::class)->get('hiring.hires', $query)->value)->toBe(1.0);

    cachingHire();

    expect(app(MetricService::class)->get('hiring.hires', $query)->value)->toBe(1.0)
        ->and(app(MetricService::class)->get('hiring.hires', MetricQuery::make(MetricPeriod::lastDays(8), null))->value)->toBe(2.0);
});

test('a cached result is never shared between viewers with different teams', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $manager = Employee::factory()->create();
    $recruiter = Employee::factory()->reportingTo($manager)->create();
    cachingHire($recruiter);
    cachingHire();

    $manager = User::factory()->create(['employee_id' => $manager->id])->assignRole('manager');
    $chro = User::factory()->create()->assignRole('chro');
    $period = MetricPeriod::lastDays(7);

    expect(app(MetricService::class)->get('hiring.hires', MetricQuery::make($period, $manager))->value)->toBe(1.0)
        ->and(app(MetricService::class)->get('hiring.hires', MetricQuery::make($period, $chro))->value)->toBe(2.0);
});

test('a cached result survives a store that never unserializes objects', function (): void {
    // The application's cache refuses to unserialize objects (cache.serializable_classes = false).
    config(['cache.default' => 'file', 'cache.stores.file.path' => storage_path('framework/cache/metrics-test'), 'cache.serializable_classes' => false]);
    cachingHire();
    $query = MetricQuery::make(MetricPeriod::lastDays(7), null);

    $first = app(MetricService::class)->get('hiring.time_to_hire', $query);
    $second = app(MetricService::class)->get('hiring.time_to_hire', $query);
    $offers = app(MetricService::class)->get('outcome.status_observations', $query);

    expect($second)->toBeInstanceOf(\App\Services\Metrics\MetricResult::class)
        ->and($second->toArray())->toBe($first->toArray())
        ->and(app(MetricService::class)->get('outcome.status_observations', $query)->toArray())->toBe($offers->toArray());

    \Illuminate\Support\Facades\Cache::store('file')->flush();
});

test('a real-time metric is never cached', function (): void {
    $query = MetricQuery::make(null, null);

    app(MetricService::class)->get('requisition.ageing', $query);
    RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open, 'opening_date' => now()->subDays(3)]);

    expect(app(MetricService::class)->get('requisition.ageing', $query)->detail('open'))->toBe(1);
});
