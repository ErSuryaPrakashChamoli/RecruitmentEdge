<?php

use App\Enums\IncentiveAdjustmentType;
use App\Enums\IncentiveCalculationStatus;
use App\Models\Employee;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\User;
use App\Services\IncentiveApprovalService;

beforeEach(function (): void {
    $this->service = app(IncentiveApprovalService::class);
});

test('a calculation moves through its full lifecycle to paid', function (): void {
    $calculation = RecruiterIncentiveCalculation::factory()->create(['status' => IncentiveCalculationStatus::Calculated]);

    $this->service->moveTo($calculation, IncentiveCalculationStatus::PendingVerification);
    $this->service->moveTo($calculation->fresh(), IncentiveCalculationStatus::Approved);
    $this->service->moveTo($calculation->fresh(), IncentiveCalculationStatus::Payable);

    $calculation->refresh();
    expect($calculation->status)->toBe(IncentiveCalculationStatus::Payable)
        ->and($calculation->approvals()->count())->toBe(3);

    $this->service->pay($calculation, 1000.0, now(), 'REF-001');

    expect($calculation->refresh()->status)->toBe(IncentiveCalculationStatus::Paid)
        ->and($calculation->payments()->count())->toBe(1)
        ->and((float) $calculation->payments()->first()->amount)->toBe(1000.0);
});

test('an invalid transition is rejected', function (): void {
    $calculation = RecruiterIncentiveCalculation::factory()->create(['status' => IncentiveCalculationStatus::Calculated]);

    $this->service->moveTo($calculation, IncentiveCalculationStatus::Approved);
})->throws(DomainException::class);

test('paying a non-payable calculation is rejected', function (): void {
    $calculation = RecruiterIncentiveCalculation::factory()->create(['status' => IncentiveCalculationStatus::Approved]);

    $this->service->pay($calculation, 1000.0, now());
})->throws(DomainException::class);

test('an adjustment changes the effective amount without touching the original', function (): void {
    $calculation = RecruiterIncentiveCalculation::factory()->create(['amount' => 1000]);

    $this->service->adjust($calculation, -200, 'Partial correction');

    expect((float) $calculation->refresh()->amount)->toBe(1000.0)
        ->and($calculation->effectiveAmount())->toBe(800.0);
});

test('reversing a calculation zeroes its effective amount and moves it to Reversed', function (): void {
    $calculation = RecruiterIncentiveCalculation::factory()->create(['amount' => 1000, 'status' => IncentiveCalculationStatus::Approved]);

    $this->service->reverse($calculation, 'Candidate did not complete probation');

    expect($calculation->refresh()->status)->toBe(IncentiveCalculationStatus::Reversed)
        ->and($calculation->effectiveAmount())->toBe(0.0)
        ->and($calculation->adjustments()->first()->adjustment_type)->toBe(IncentiveAdjustmentType::Reversal);
});

test('approving a calculation notifies the recruiter', function (): void {
    $employee = Employee::factory()->create();
    $user = User::factory()->create(['employee_id' => $employee->id]);
    $calculation = RecruiterIncentiveCalculation::factory()->create([
        'employee_id' => $employee->id,
        'status' => IncentiveCalculationStatus::PendingVerification,
    ]);

    $this->service->moveTo($calculation, IncentiveCalculationStatus::Approved);

    expect($user->notifications()->count())->toBe(1)
        ->and($user->notifications()->first()->data['title'])->toBe('[Incentives] Incentive approved');
});

test('allowedNextStatuses reflects the current status', function (): void {
    $calculation = RecruiterIncentiveCalculation::factory()->create(['status' => IncentiveCalculationStatus::Payable]);

    expect(array_map(fn (IncentiveCalculationStatus $s) => $s->value, $this->service->allowedNextStatuses($calculation)))
        ->toBe(['paid', 'reversed']);
});
