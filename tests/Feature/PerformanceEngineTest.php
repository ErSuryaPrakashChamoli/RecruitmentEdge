<?php

use App\Enums\ActivityOutcome;
use App\Enums\ActivityType;
use App\Enums\TargetMetric;
use App\Enums\TargetPeriodType;
use App\Models\Employee;
use App\Models\RecruiterPerformanceRule;
use App\Models\RecruiterPerformanceSnapshot;
use App\Models\RecruitmentDailyActivity;
use App\Models\RecruitmentDailyTarget;
use App\Services\PerformanceEngine;

beforeEach(function (): void {
    $this->engine = app(PerformanceEngine::class);
    $this->start = now()->startOfMonth();
    $this->end = now()->endOfMonth();
});

function makeCallActivities(Employee $recruiter, int $total, int $connected): void
{
    RecruitmentDailyActivity::factory()->count($connected)->create([
        'recruiter_id' => $recruiter->id,
        'activity_type' => ActivityType::Call,
        'outcome' => ActivityOutcome::Connected,
        'activity_datetime' => now(),
    ]);

    RecruitmentDailyActivity::factory()->count($total - $connected)->create([
        'recruiter_id' => $recruiter->id,
        'activity_type' => ActivityType::Call,
        'outcome' => ActivityOutcome::NoAnswer,
        'activity_datetime' => now(),
    ]);
}

test('computes a weighted composite score across configured metrics', function (): void {
    $recruiter = Employee::factory()->create();

    RecruitmentDailyTarget::factory()->create([
        'employee_id' => $recruiter->id, 'metric' => TargetMetric::Calls, 'target_value' => 10, 'period_type' => TargetPeriodType::Monthly, 'effective_from' => $this->start,
    ]);
    RecruitmentDailyTarget::factory()->create([
        'employee_id' => $recruiter->id, 'metric' => TargetMetric::ConnectedCalls, 'target_value' => 5, 'period_type' => TargetPeriodType::Monthly, 'effective_from' => $this->start,
    ]);

    makeCallActivities($recruiter, total: 12, connected: 5);

    RecruiterPerformanceRule::factory()->create(['metric' => TargetMetric::Calls, 'weightage' => 60, 'effective_from' => $this->start]);
    RecruiterPerformanceRule::factory()->create(['metric' => TargetMetric::ConnectedCalls, 'weightage' => 40, 'effective_from' => $this->start]);

    $result = $this->engine->computeFor($recruiter, $this->start, $this->end);

    // Calls: 12/10 = 120%, ConnectedCalls: 5/5 = 100%. Weighted: (120*60 + 100*40) / 100 = 112.0
    expect($result['score'])->toBe(112.0)
        ->and($result['breakdown'])->toHaveCount(2);
});

test('a metric with no configured target is excluded rather than dragging the score to zero', function (): void {
    $recruiter = Employee::factory()->create();

    RecruitmentDailyTarget::factory()->create([
        'employee_id' => $recruiter->id, 'metric' => TargetMetric::Calls, 'target_value' => 10, 'period_type' => TargetPeriodType::Monthly, 'effective_from' => $this->start,
    ]);
    makeCallActivities($recruiter, total: 12, connected: 0);

    RecruiterPerformanceRule::factory()->create(['metric' => TargetMetric::Calls, 'weightage' => 60, 'effective_from' => $this->start]);
    RecruiterPerformanceRule::factory()->create(['metric' => TargetMetric::Screening, 'weightage' => 40, 'effective_from' => $this->start]);

    $result = $this->engine->computeFor($recruiter, $this->start, $this->end);

    expect($result['score'])->toBe(120.0);
});

test('weights are self-normalizing and need not sum to 100', function (): void {
    $recruiter = Employee::factory()->create();

    RecruitmentDailyTarget::factory()->create([
        'employee_id' => $recruiter->id, 'metric' => TargetMetric::Calls, 'target_value' => 10, 'period_type' => TargetPeriodType::Monthly, 'effective_from' => $this->start,
    ]);
    makeCallActivities($recruiter, total: 12, connected: 0);

    RecruiterPerformanceRule::factory()->create(['metric' => TargetMetric::Calls, 'weightage' => 30, 'effective_from' => $this->start]);

    $result = $this->engine->computeFor($recruiter, $this->start, $this->end);

    expect($result['score'])->toBe(120.0);
});

test('no configured rules produces a null score', function (): void {
    $recruiter = Employee::factory()->create();

    $result = $this->engine->computeFor($recruiter, $this->start, $this->end);

    expect($result['score'])->toBeNull()
        ->and($result['breakdown'])->toBe([]);
});

test('snapshotFor upserts a single snapshot per recruiter and period', function (): void {
    $recruiter = Employee::factory()->create();

    RecruitmentDailyTarget::factory()->create([
        'employee_id' => $recruiter->id, 'metric' => TargetMetric::Calls, 'target_value' => 10, 'period_type' => TargetPeriodType::Monthly, 'effective_from' => $this->start,
    ]);
    RecruiterPerformanceRule::factory()->create(['metric' => TargetMetric::Calls, 'weightage' => 100, 'effective_from' => $this->start]);

    makeCallActivities($recruiter, total: 5, connected: 0);
    $this->engine->snapshotFor($recruiter, $this->start, $this->end);

    makeCallActivities($recruiter, total: 5, connected: 0);
    $this->engine->snapshotFor($recruiter, $this->start, $this->end);

    expect(RecruiterPerformanceSnapshot::query()->count())->toBe(1)
        ->and((float) RecruiterPerformanceSnapshot::query()->first()->score)->toBe(100.0);
});
