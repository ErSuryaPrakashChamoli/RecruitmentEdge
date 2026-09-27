<?php

use App\Enums\TargetMetric;
use App\Enums\TargetPeriodType;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\RecruiterPerformanceRule;
use App\Models\RecruiterPerformanceSnapshot;
use App\Models\RecruitmentDailyTarget;
use Illuminate\Support\Carbon;

/**
 * Phase 8.6 (D8.6-016/017): effective ranges run forwards; one scope has one target per metric and
 * period at a time; a metric is weighted by one performance rule at a time; a month whose freeze
 * was missed is frozen by the next run.
 */
test('a target cannot overlap another target for the same scope, metric and period', function (): void {
    $recruiter = Employee::factory()->create();
    $base = ['employee_id' => $recruiter->id, 'metric' => TargetMetric::Joining, 'period_type' => TargetPeriodType::Monthly];
    RecruitmentDailyTarget::factory()->create([...$base, 'effective_from' => '2026-09-01', 'effective_to' => '2026-09-30']);

    expect(fn () => RecruitmentDailyTarget::factory()->create([...$base, 'effective_from' => '2026-09-15', 'effective_to' => null]))->toThrow(DomainException::class, 'already covers')
        ->and(fn () => RecruitmentDailyTarget::factory()->create([...$base, 'effective_from' => '2026-10-01', 'effective_to' => '2026-09-01']))->toThrow(DomainException::class, 'effective-to');

    // Adjacent ranges, another period type and another recruiter are all fine.
    RecruitmentDailyTarget::factory()->create([...$base, 'effective_from' => '2026-10-01']);
    RecruitmentDailyTarget::factory()->create([...$base, 'period_type' => TargetPeriodType::Daily, 'effective_from' => '2026-09-01']);
    RecruitmentDailyTarget::factory()->create([...$base, 'employee_id' => Employee::factory()->create()->id, 'effective_from' => '2026-09-01']);

    expect(RecruitmentDailyTarget::query()->count())->toBe(4);
});

test('a performance rule cannot weight a metric twice for the same dates', function (): void {
    RecruiterPerformanceRule::factory()->create(['metric' => TargetMetric::Joining, 'effective_from' => '2026-01-01', 'effective_to' => null]);

    expect(fn () => RecruiterPerformanceRule::factory()->create(['metric' => TargetMetric::Joining, 'effective_from' => '2026-06-01']))->toThrow(DomainException::class, 'already weights')
        ->and(fn () => RecruiterPerformanceRule::factory()->create(['metric' => TargetMetric::Calls, 'effective_from' => '2026-06-01', 'effective_to' => '2026-05-01']))->toThrow(DomainException::class, 'effective-to');

    RecruiterPerformanceRule::factory()->create(['metric' => TargetMetric::Calls, 'effective_from' => '2026-06-01']);
    expect(RecruiterPerformanceRule::query()->count())->toBe(2);
});

test('a month whose freeze was missed is frozen by the next daily run, and frozen months are left alone', function (): void {
    $recruiter = Employee::factory()->create();
    CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);

    // The scheduler ran during August but not on 1 September.
    $this->travelTo(Carbon::parse('2026-08-20 10:00'));
    $this->artisan('performance:snapshot')->assertSuccessful();
    $this->travelTo(Carbon::parse('2026-09-12 10:00'));

    $this->artisan('performance:snapshot')
        ->expectsOutputToContain('Froze 1 snapshot(s) for August 2026')
        ->assertSuccessful();

    $august = RecruiterPerformanceSnapshot::query()->whereDate('period_start', '2026-08-01')->sole();
    $frozenAt = $august->frozen_at;

    expect($frozenAt)->not->toBeNull()
        ->and(RecruiterPerformanceSnapshot::query()->whereDate('period_start', '2026-09-01')->sole()->frozen_at)->toBeNull();

    $this->travelTo(Carbon::parse('2026-09-13 10:00'));
    $this->artisan('performance:snapshot')->doesntExpectOutputToContain('August 2026')->assertSuccessful();

    expect($august->fresh()->frozen_at->equalTo($frozenAt))->toBeTrue();
});
