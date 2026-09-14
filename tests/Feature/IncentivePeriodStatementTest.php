<?php

use App\Enums\IncentiveAdjustmentType;
use App\Enums\IncentiveCalculationStatus;
use App\Filament\Pages\IncentiveDashboard;
use App\Filament\Resources\RecruiterIncentiveCalculations\Pages\ListRecruiterIncentiveCalculations;
use App\Models\Employee;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\RecruiterIncentivePayment;
use App\Models\User;
use App\Services\IncentiveStatementService;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->service = app(IncentiveStatementService::class);
});

test('the period statement covers only the recruiter\'s calculations in that month, with totals and payments', function (): void {
    $recruiter = Employee::factory()->create();

    $approved = RecruiterIncentiveCalculation::factory()->create([
        'employee_id' => $recruiter->id,
        'amount' => 1000,
        'status' => IncentiveCalculationStatus::Approved,
    ]);
    $approved->adjustments()->create(['adjustment_type' => IncentiveAdjustmentType::Correction, 'amount_delta' => -200, 'reason' => 'Correction']);

    $paid = RecruiterIncentiveCalculation::factory()->create([
        'employee_id' => $recruiter->id,
        'amount' => 500,
        'status' => IncentiveCalculationStatus::Paid,
    ]);
    RecruiterIncentivePayment::factory()->create([
        'recruiter_incentive_calculation_id' => $paid->id,
        'amount' => 500,
        'payment_reference' => 'NEFT-001',
    ]);

    RecruiterIncentiveCalculation::factory()->create([
        'employee_id' => $recruiter->id,
        'period_start' => now()->subMonthNoOverflow()->startOfMonth(),
        'period_end' => now()->subMonthNoOverflow()->endOfMonth(),
    ]);
    RecruiterIncentiveCalculation::factory()->create();

    $statement = $this->service->periodStatement($recruiter, now());

    expect($statement['calculations']->pluck('id')->sort()->values()->all())->toBe(collect([$approved->id, $paid->id])->sort()->values()->all())
        ->and($statement['totalsByStatus']['approved']['amount'])->toBe(800.0)
        ->and($statement['totalsByStatus']['paid']['amount'])->toBe(500.0)
        ->and($statement['earnedTotal'])->toBe(1300.0)
        ->and($statement['paidTotal'])->toBe(500.0)
        ->and($statement['payments']->first()->payment_reference)->toBe('NEFT-001');

    $html = view('pdf.incentive-statement-period', $statement)->render();

    expect($html)->toContain($approved->incentiveRule->name)
        ->toContain('NEFT-001')
        ->toContain('800.00');
});

test('a recruiter can download their own period statement from the incentive dashboard', function (): void {
    $recruiter = Employee::factory()->create();
    RecruiterIncentiveCalculation::factory()->create(['employee_id' => $recruiter->id]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('recruiter');
    actingAs($user);

    Livewire::test(IncentiveDashboard::class)
        ->callAction('downloadStatement', data: ['month' => now()->format('Y-m')])
        ->assertFileDownloaded($this->service->filename($recruiter, now()));
});

test('an approver can download a period statement for a recruiter in their hierarchy from the calculations list', function (): void {
    $vpEmployee = Employee::factory()->create();
    $recruiter = Employee::factory()->reportingTo($vpEmployee)->create();
    RecruiterIncentiveCalculation::factory()->create(['employee_id' => $recruiter->id]);

    $vp = User::factory()->create(['employee_id' => $vpEmployee->id]);
    $vp->assignRole('vp_hr');
    actingAs($vp);

    Livewire::test(ListRecruiterIncentiveCalculations::class)
        ->callAction('downloadPeriodStatement', data: ['employee_id' => $recruiter->id, 'month' => now()->format('Y-m')])
        ->assertFileDownloaded($this->service->filename($recruiter, now()));
});

test('a recruiter outside the viewer\'s hierarchy cannot be chosen for a statement', function (): void {
    $vpEmployee = Employee::factory()->create();
    $outsider = Employee::factory()->create();

    $vp = User::factory()->create(['employee_id' => $vpEmployee->id]);
    $vp->assignRole('vp_hr');
    actingAs($vp);

    Livewire::test(ListRecruiterIncentiveCalculations::class)
        ->callAction('downloadPeriodStatement', data: ['employee_id' => $outsider->id, 'month' => now()->format('Y-m')])
        ->assertHasFormErrors(['employee_id']);

    expect($this->service->canDownloadFor($vp, $outsider))->toBeFalse();
});

test('statement download permissions: own needs incentives.view, others need export or approve within hierarchy', function (): void {
    $managerEmployee = Employee::factory()->create();
    $recruiter = Employee::factory()->reportingTo($managerEmployee)->create();

    $recruiterUser = User::factory()->create(['employee_id' => $recruiter->id]);
    $recruiterUser->assignRole('recruiter');

    $managerUser = User::factory()->create(['employee_id' => $managerEmployee->id]);
    $managerUser->assignRole('manager');

    expect($this->service->canDownloadFor($recruiterUser, $recruiter))->toBeTrue()
        ->and($this->service->canDownloadFor($recruiterUser, $managerEmployee))->toBeFalse()
        ->and($this->service->canDownloadFor($managerUser, $recruiter))->toBeTrue();
});
