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

test('the plain transition refuses Paid so a payment must be recorded', function (): void {
    $calculation = RecruiterIncentiveCalculation::factory()->create(['status' => IncentiveCalculationStatus::Payable]);

    expect(fn () => $this->service->moveTo($calculation, IncentiveCalculationStatus::Paid))
        ->toThrow(DomainException::class, 'recording a payment');

    expect($calculation->refresh()->status)->toBe(IncentiveCalculationStatus::Payable)
        ->and($calculation->payments()->count())->toBe(0);
});

test('the plain transition refuses Reversed so the reversal adjustment is always recorded', function (): void {
    $calculation = RecruiterIncentiveCalculation::factory()->create(['status' => IncentiveCalculationStatus::Approved]);

    $this->service->moveTo($calculation, IncentiveCalculationStatus::Reversed);
})->throws(DomainException::class);

test('recording a payment requires a reference', function (): void {
    $calculation = RecruiterIncentiveCalculation::factory()->create(['status' => IncentiveCalculationStatus::Payable]);

    expect(fn () => $this->service->pay($calculation, 1000.0, now(), ''))
        ->toThrow(DomainException::class, 'reference');

    expect($calculation->refresh()->status)->toBe(IncentiveCalculationStatus::Payable);
});

test('rejecting and reversing require remarks', function (): void {
    $pending = RecruiterIncentiveCalculation::factory()->create(['status' => IncentiveCalculationStatus::PendingVerification]);
    $approved = RecruiterIncentiveCalculation::factory()->create(['status' => IncentiveCalculationStatus::Approved]);

    expect(fn () => $this->service->reject($pending, '  '))->toThrow(DomainException::class)
        ->and(fn () => $this->service->reverse($approved, ''))->toThrow(DomainException::class);

    expect($pending->refresh()->status)->toBe(IncentiveCalculationStatus::PendingVerification)
        ->and($approved->refresh()->status)->toBe(IncentiveCalculationStatus::Approved)
        ->and($approved->adjustments()->count())->toBe(0);
});

test('a reversal is attributed to the actor with their remarks', function (): void {
    $actor = Employee::factory()->create();
    $calculation = RecruiterIncentiveCalculation::factory()->create(['amount' => 1000, 'status' => IncentiveCalculationStatus::Paid]);

    $this->service->reverse($calculation, 'Candidate absconded', $actor);

    $adjustment = $calculation->adjustments()->first();
    $approval = $calculation->approvals()->first();

    expect($adjustment->created_by)->toBe($actor->id)
        ->and($adjustment->reason)->toBe('Candidate absconded')
        ->and($approval->changed_by)->toBe($actor->id)
        ->and($approval->from_status)->toBe(IncentiveCalculationStatus::Paid)
        ->and($approval->to_status)->toBe(IncentiveCalculationStatus::Reversed);
});

test('submitting for verification is refused while a retention hold is running', function (): void {
    $calculation = RecruiterIncentiveCalculation::factory()->create([
        'status' => IncentiveCalculationStatus::Calculated,
        'retention_due_at' => now()->addDays(5),
    ]);

    $this->service->submitForVerification($calculation);
})->throws(DomainException::class, 'retention hold');

test('a recalculated status change is written to the trail, but only between Calculated and Pending Verification', function (): void {
    $calculation = RecruiterIncentiveCalculation::factory()->create(['status' => IncentiveCalculationStatus::PendingVerification]);

    $this->service->applyRecalculatedStatus($calculation, IncentiveCalculationStatus::Calculated);

    expect($calculation->refresh()->status)->toBe(IncentiveCalculationStatus::Calculated)
        ->and($calculation->approvals()->count())->toBe(1)
        ->and($calculation->approvals()->first()->from_status)->toBe(IncentiveCalculationStatus::PendingVerification);

    $approved = RecruiterIncentiveCalculation::factory()->create(['status' => IncentiveCalculationStatus::Approved]);

    expect(fn () => $this->service->applyRecalculatedStatus($approved, IncentiveCalculationStatus::Calculated))
        ->toThrow(DomainException::class);
});

test('allowedNextStatuses reflects the current status', function (): void {
    $calculation = RecruiterIncentiveCalculation::factory()->create(['status' => IncentiveCalculationStatus::Payable]);

    expect(array_map(fn (IncentiveCalculationStatus $s) => $s->value, $this->service->allowedNextStatuses($calculation)))
        ->toBe(['paid', 'reversed']);
});
