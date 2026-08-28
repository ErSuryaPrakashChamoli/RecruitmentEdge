<?php

use App\Enums\TargetMetric;
use App\Enums\TargetPeriodType;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\RecruitmentDailyTarget;
use App\Services\TargetResolutionService;

beforeEach(function (): void {
    $this->service = app(TargetResolutionService::class);
});

test('an employee-specific target wins over designation and department targets', function (): void {
    $department = Department::factory()->create();
    $designation = Designation::factory()->create(['department_id' => $department->id]);
    $recruiter = Employee::factory()->create(['department_id' => $department->id, 'designation_id' => $designation->id]);

    RecruitmentDailyTarget::factory()->create([
        'employee_id' => null,
        'department_id' => $department->id,
        'designation_id' => null,
        'metric' => TargetMetric::Calls,
        'target_value' => 50,
        'effective_from' => now()->subDays(10),
    ]);

    RecruitmentDailyTarget::factory()->create([
        'employee_id' => null,
        'department_id' => null,
        'designation_id' => $designation->id,
        'metric' => TargetMetric::Calls,
        'target_value' => 75,
        'effective_from' => now()->subDays(10),
    ]);

    RecruitmentDailyTarget::factory()->create([
        'employee_id' => $recruiter->id,
        'department_id' => null,
        'designation_id' => null,
        'metric' => TargetMetric::Calls,
        'target_value' => 100,
        'effective_from' => now()->subDays(10),
    ]);

    expect($this->service->resolve($recruiter, TargetMetric::Calls, now()))->toBe(100);
});

test('falls back to designation target when no employee-specific target exists', function (): void {
    $department = Department::factory()->create();
    $designation = Designation::factory()->create(['department_id' => $department->id]);
    $recruiter = Employee::factory()->create(['department_id' => $department->id, 'designation_id' => $designation->id]);

    RecruitmentDailyTarget::factory()->create([
        'employee_id' => null,
        'department_id' => $department->id,
        'designation_id' => null,
        'metric' => TargetMetric::Calls,
        'target_value' => 50,
        'effective_from' => now()->subDays(10),
    ]);

    RecruitmentDailyTarget::factory()->create([
        'employee_id' => null,
        'department_id' => null,
        'designation_id' => $designation->id,
        'metric' => TargetMetric::Calls,
        'target_value' => 75,
        'effective_from' => now()->subDays(10),
    ]);

    expect($this->service->resolve($recruiter, TargetMetric::Calls, now()))->toBe(75);
});

test('returns null when no target is configured', function (): void {
    $recruiter = Employee::factory()->create();

    expect($this->service->resolve($recruiter, TargetMetric::Calls, now()))->toBeNull();
});

test('ignores a target outside its effective date range', function (): void {
    $recruiter = Employee::factory()->create();

    RecruitmentDailyTarget::factory()->create([
        'employee_id' => $recruiter->id,
        'metric' => TargetMetric::Calls,
        'target_value' => 100,
        'effective_from' => now()->subMonths(2),
        'effective_to' => now()->subMonth(),
    ]);

    expect($this->service->resolve($recruiter, TargetMetric::Calls, now()))->toBeNull();
});

test('respects period type when resolving', function (): void {
    $recruiter = Employee::factory()->create();

    RecruitmentDailyTarget::factory()->create([
        'employee_id' => $recruiter->id,
        'metric' => TargetMetric::Calls,
        'period_type' => TargetPeriodType::Monthly,
        'target_value' => 1000,
        'effective_from' => now()->startOfMonth(),
    ]);

    expect($this->service->resolve($recruiter, TargetMetric::Calls, now(), TargetPeriodType::Daily))->toBeNull()
        ->and($this->service->resolve($recruiter, TargetMetric::Calls, now(), TargetPeriodType::Monthly))->toBe(1000);
});

test('resolveForRange multiplies a daily target by the number of days in the range', function (): void {
    $recruiter = Employee::factory()->create();

    RecruitmentDailyTarget::factory()->create([
        'employee_id' => $recruiter->id,
        'metric' => TargetMetric::Calls,
        'period_type' => TargetPeriodType::Daily,
        'target_value' => 20,
        'effective_from' => now()->startOfMonth(),
    ]);

    $start = now()->startOfMonth();
    $end = now()->startOfMonth()->addDays(9);

    expect($this->service->resolveForRange($recruiter, TargetMetric::Calls, $start, $end))->toBe(200);
});

test('resolveForRange uses a monthly target as-is without multiplying', function (): void {
    $recruiter = Employee::factory()->create();

    RecruitmentDailyTarget::factory()->create([
        'employee_id' => $recruiter->id,
        'metric' => TargetMetric::Calls,
        'period_type' => TargetPeriodType::Monthly,
        'target_value' => 500,
        'effective_from' => now()->startOfMonth(),
    ]);

    expect($this->service->resolveForRange($recruiter, TargetMetric::Calls, now()->startOfMonth(), now()->endOfMonth()))->toBe(500);
});
