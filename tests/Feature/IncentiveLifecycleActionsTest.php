<?php

use App\Enums\IncentiveAdjustmentType;
use App\Enums\IncentiveCalculationStatus;
use App\Filament\Resources\RecruiterIncentiveCalculations\Pages\ListRecruiterIncentiveCalculations;
use App\Filament\Resources\RecruiterIncentiveCalculations\Pages\ViewRecruiterIncentiveCalculation;
use App\Models\Employee;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $this->chroEmployee = Employee::factory()->create();
    $this->chro = User::factory()->create(['employee_id' => $this->chroEmployee->id]);
    $this->chro->assignRole('chro');

    $this->vpEmployee = Employee::factory()->create();
    $this->recruiter = Employee::factory()->reportingTo($this->vpEmployee)->create();
    $this->vp = User::factory()->create(['employee_id' => $this->vpEmployee->id]);
    $this->vp->assignRole('vp_hr');
});

function viewCalculationPage(RecruiterIncentiveCalculation $calculation): mixed
{
    return Livewire::test(ViewRecruiterIncentiveCalculation::class, ['record' => $calculation->getRouteKey()]);
}

test('the generic Change Status action is gone', function (): void {
    actingAs($this->chro);
    $calculation = RecruiterIncentiveCalculation::factory()->create(['employee_id' => $this->recruiter->id]);

    Livewire::test(ListRecruiterIncentiveCalculations::class)
        ->assertActionDoesNotExist(TestAction::make('changeStatus')->table($calculation));
});

test('an approver approves and marks payable, and each step is attributed in the trail', function (): void {
    actingAs($this->vp);
    $calculation = RecruiterIncentiveCalculation::factory()->create([
        'employee_id' => $this->recruiter->id,
        'status' => IncentiveCalculationStatus::PendingVerification,
    ]);

    viewCalculationPage($calculation)
        ->callAction('approve', data: ['remarks' => 'Verified joining'])
        ->assertNotified();

    viewCalculationPage($calculation->refresh())
        ->callAction('markPayable');

    $trail = $calculation->approvals()->reorder('id')->get();

    expect($calculation->refresh()->status)->toBe(IncentiveCalculationStatus::Payable)
        ->and($trail->pluck('to_status')->all())->toBe([IncentiveCalculationStatus::Approved, IncentiveCalculationStatus::Payable])
        ->and($trail->pluck('changed_by')->unique()->all())->toBe([$this->vpEmployee->id])
        ->and($trail->first()->remarks)->toBe('Verified joining');
});

test('an approver without incentives.pay cannot see Record Payment', function (): void {
    actingAs($this->vp);
    $calculation = RecruiterIncentiveCalculation::factory()->create([
        'employee_id' => $this->recruiter->id,
        'status' => IncentiveCalculationStatus::Payable,
    ]);

    viewCalculationPage($calculation)
        ->assertActionHidden('recordPayment')
        ->assertActionVisible('reverse');
});

test('Record Payment from the table records the payment and is the path to Paid', function (): void {
    actingAs($this->chro);
    $calculation = RecruiterIncentiveCalculation::factory()->create([
        'employee_id' => $this->recruiter->id,
        'amount' => 1200,
        'status' => IncentiveCalculationStatus::Payable,
    ]);

    Livewire::test(ListRecruiterIncentiveCalculations::class)
        ->callAction(TestAction::make('recordPayment')->table($calculation), data: [
            'amount' => 1200,
            'payment_date' => now()->toDateString(),
            'payment_reference' => 'NEFT-778',
        ])
        ->assertHasNoFormErrors();

    $payment = $calculation->payments()->first();

    expect($calculation->refresh()->status)->toBe(IncentiveCalculationStatus::Paid)
        ->and($payment->payment_reference)->toBe('NEFT-778')
        ->and($payment->paid_by)->toBe($this->chroEmployee->id);
});

test('Record Payment requires a reference', function (): void {
    actingAs($this->chro);
    $calculation = RecruiterIncentiveCalculation::factory()->create(['status' => IncentiveCalculationStatus::Payable]);

    viewCalculationPage($calculation)
        ->callAction('recordPayment', data: ['amount' => 1000, 'payment_date' => now()->toDateString(), 'payment_reference' => ''])
        ->assertHasFormErrors(['payment_reference' => 'required']);

    expect($calculation->refresh()->status)->toBe(IncentiveCalculationStatus::Payable);
});

test('Reject requires remarks', function (): void {
    actingAs($this->vp);
    $calculation = RecruiterIncentiveCalculation::factory()->create([
        'employee_id' => $this->recruiter->id,
        'status' => IncentiveCalculationStatus::PendingVerification,
    ]);

    viewCalculationPage($calculation)
        ->callAction('reject', data: ['remarks' => ''])
        ->assertHasFormErrors(['remarks' => 'required']);

    expect($calculation->refresh()->status)->toBe(IncentiveCalculationStatus::PendingVerification);
});

test('reversing a paid incentive needs incentives.pay and records an attributed reversal', function (): void {
    $calculation = RecruiterIncentiveCalculation::factory()->create([
        'employee_id' => $this->recruiter->id,
        'amount' => 1000,
        'status' => IncentiveCalculationStatus::Paid,
    ]);

    actingAs($this->vp);
    viewCalculationPage($calculation)->assertActionHidden('reverse');

    actingAs($this->chro);
    viewCalculationPage($calculation)
        ->callAction('reverse', data: ['remarks' => 'Candidate left during probation'])
        ->assertHasNoFormErrors();

    $reversal = $calculation->adjustments()->first();

    expect($calculation->refresh()->status)->toBe(IncentiveCalculationStatus::Reversed)
        ->and($calculation->effectiveAmount())->toBe(0.0)
        ->and($reversal->adjustment_type)->toBe(IncentiveAdjustmentType::Reversal)
        ->and($reversal->created_by)->toBe($this->chroEmployee->id)
        ->and($reversal->reason)->toBe('Candidate left during probation');
});

test('a service refusal is shown as a notification instead of an error page', function (): void {
    actingAs($this->chro);
    $calculation = RecruiterIncentiveCalculation::factory()->create([
        'status' => IncentiveCalculationStatus::Calculated,
        'retention_due_at' => now()->addDays(10),
    ]);

    viewCalculationPage($calculation)
        ->callAction('submitForVerification')
        ->assertNotified('Incentive could not be updated');

    expect($calculation->refresh()->status)->toBe(IncentiveCalculationStatus::Calculated);
});
