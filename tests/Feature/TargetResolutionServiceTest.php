<?php

use App\Enums\TargetMetric;
use App\Enums\TargetPeriodType;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\RecruitmentDailyTarget;
use App\Services\TargetResolutionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

test('the designation tier ignores another employee\'s personal target that also carries the designation', function (): void {
    $recruiter = Employee::factory()->create();
    $colleague = Employee::factory()->create(['designation_id' => $recruiter->designation_id]);

    // A legacy multi-scope row written before the saving guard existed.
    DB::table('recruitment_daily_targets')->insert([
        'employee_id' => $colleague->id,
        'designation_id' => $recruiter->designation_id,
        'department_id' => $recruiter->department_id,
        'metric' => TargetMetric::Calls->value,
        'period_type' => TargetPeriodType::Daily->value,
        'target_value' => 99,
        'effective_from' => now()->subDays(10)->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($this->service->resolve($recruiter, TargetMetric::Calls, now()))->toBeNull()
        ->and($this->service->resolveForRange($recruiter, TargetMetric::Calls, now()->startOfMonth(), now()->endOfMonth()))->toBeNull();
});

test('the department tier ignores a designation-scoped target for another designation in the same department', function (): void {
    $department = Department::factory()->create();
    $otherDesignation = Designation::factory()->create(['department_id' => $department->id]);
    $recruiter = Employee::factory()->create(['department_id' => $department->id]);

    DB::table('recruitment_daily_targets')->insert([
        'employee_id' => null,
        'designation_id' => $otherDesignation->id,
        'department_id' => $department->id,
        'metric' => TargetMetric::Calls->value,
        'period_type' => TargetPeriodType::Daily->value,
        'target_value' => 75,
        'effective_from' => now()->subDays(10)->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($this->service->resolve($recruiter, TargetMetric::Calls, now()))->toBeNull();

    RecruitmentDailyTarget::factory()->create([
        'employee_id' => null,
        'department_id' => $department->id,
        'designation_id' => null,
        'metric' => TargetMetric::Calls,
        'target_value' => 50,
        'effective_from' => now()->subDays(10),
    ]);

    expect($this->service->resolve($recruiter, TargetMetric::Calls, now()))->toBe(50);
});

test('a target must be scoped to exactly one of employee, designation, or department', function (array $scope): void {
    expect(fn () => RecruitmentDailyTarget::factory()->create([
        'employee_id' => null,
        'department_id' => null,
        'designation_id' => null,
        ...$scope,
    ]))->toThrow(DomainException::class);
})->with([
    'no scope' => fn (): array => [],
    'employee and department' => fn (): array => ['employee_id' => Employee::factory()->create()->id, 'department_id' => Department::factory()->create()->id],
    'designation and department' => fn (): array => ['designation_id' => Designation::factory()->create()->id, 'department_id' => Department::factory()->create()->id],
]);

test('resolveForRange uses a weekly target as-is for a whole Monday to Sunday week', function (): void {
    $recruiter = Employee::factory()->create();

    RecruitmentDailyTarget::factory()->create([
        'employee_id' => $recruiter->id,
        'metric' => TargetMetric::Calls,
        'period_type' => TargetPeriodType::Weekly,
        'target_value' => 70,
        'effective_from' => '2026-01-01',
    ]);
    RecruitmentDailyTarget::factory()->create([
        'employee_id' => $recruiter->id,
        'metric' => TargetMetric::Calls,
        'period_type' => TargetPeriodType::Daily,
        'target_value' => 5,
        'effective_from' => '2026-01-01',
    ]);

    // 2026-09-14 is a Monday.
    expect($this->service->resolveForRange($recruiter, TargetMetric::Calls, Carbon::parse('2026-09-14'), Carbon::parse('2026-09-20')))->toBe(70);
});

test('resolveForRange prorates the most specific configured period for a range that is not a whole period', function (): void {
    $recruiter = Employee::factory()->create();

    RecruitmentDailyTarget::factory()->create([
        'employee_id' => $recruiter->id,
        'metric' => TargetMetric::Calls,
        'period_type' => TargetPeriodType::Monthly,
        'target_value' => 300,
        'effective_from' => '2026-01-01',
    ]);

    // 10 of September's 30 days.
    expect($this->service->resolveForRange($recruiter, TargetMetric::Calls, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-10')))->toBe(100);

    RecruitmentDailyTarget::factory()->create([
        'employee_id' => $recruiter->id,
        'metric' => TargetMetric::Calls,
        'period_type' => TargetPeriodType::Weekly,
        'target_value' => 70,
        'effective_from' => '2026-01-01',
    ]);

    // Weekly is more specific than monthly: 70 × 10 / 7.
    expect($this->service->resolveForRange($recruiter, TargetMetric::Calls, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-10')))->toBe(100)
        ->and($this->service->resolveForRange($recruiter, TargetMetric::Calls, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-03')))->toBe(30)
        // A whole month still uses the monthly target as-is.
        ->and($this->service->resolveForRange($recruiter, TargetMetric::Calls, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30')))->toBe(300);
});

test('resolveForRange prorates a weekly target over a whole month when no monthly target exists', function (): void {
    $recruiter = Employee::factory()->create();

    RecruitmentDailyTarget::factory()->create([
        'employee_id' => $recruiter->id,
        'metric' => TargetMetric::Calls,
        'period_type' => TargetPeriodType::Weekly,
        'target_value' => 70,
        'effective_from' => '2026-01-01',
    ]);

    expect($this->service->resolveForRange($recruiter, TargetMetric::Calls, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30')))->toBe(300);
});

test('resolveForRange prorates a monthly target per calendar month across a range spanning two months', function (): void {
    $recruiter = Employee::factory()->create();

    RecruitmentDailyTarget::factory()->create([
        'employee_id' => $recruiter->id,
        'metric' => TargetMetric::Calls,
        'period_type' => TargetPeriodType::Monthly,
        'target_value' => 310,
        'effective_from' => '2026-01-01',
    ]);

    // 15/30 of September + 15/31 of October = 155 + 150.
    expect($this->service->resolveForRange($recruiter, TargetMetric::Calls, Carbon::parse('2026-09-16'), Carbon::parse('2026-10-15')))->toBe(305);
});

test('a more specific scope wins over a less specific scope regardless of period type', function (): void {
    $department = Department::factory()->create();
    $recruiter = Employee::factory()->create(['department_id' => $department->id]);

    RecruitmentDailyTarget::factory()->create([
        'employee_id' => null,
        'department_id' => $department->id,
        'metric' => TargetMetric::Calls,
        'period_type' => TargetPeriodType::Monthly,
        'target_value' => 1000,
        'effective_from' => '2026-01-01',
    ]);
    RecruitmentDailyTarget::factory()->create([
        'employee_id' => $recruiter->id,
        'metric' => TargetMetric::Calls,
        'period_type' => TargetPeriodType::Daily,
        'target_value' => 10,
        'effective_from' => '2026-01-01',
    ]);

    expect($this->service->resolveForRange($recruiter, TargetMetric::Calls, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30')))->toBe(300);
});
