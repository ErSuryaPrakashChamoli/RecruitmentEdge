<?php

use App\Enums\IncentiveCalculationStatus;
use App\Filament\Resources\RecruiterIncentiveCalculations\Pages\ViewRecruiterIncentiveCalculation;
use App\Models\Employee;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\User;
use App\Services\IncentiveApprovalService;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/*
 * Phase 8.10 (P810-SEC-008): whoever earns an incentive (e.g. a VP HR's own referral bonus) never
 * approves, marks payable, adjusts or pays it. Another approver in scope still can.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->chroEmployee = Employee::factory()->create();
    $this->chro = User::factory()->create(['employee_id' => $this->chroEmployee->id])->assignRole('chro');
    $this->vpEmployee = Employee::factory()->reportingTo($this->chroEmployee)->create();
    $this->vp = User::factory()->create(['employee_id' => $this->vpEmployee->id])->assignRole('vp_hr');
    $this->service = app(IncentiveApprovalService::class);
});

function ownCalculation(Employee $beneficiary, IncentiveCalculationStatus $status): RecruiterIncentiveCalculation
{
    return RecruiterIncentiveCalculation::factory()->create(['employee_id' => $beneficiary->id, 'status' => $status, 'amount' => 5000]);
}

test('the beneficiary sees no Approve, Mark Payable, Adjust or Record Payment on their own incentive', function (): void {
    $pending = ownCalculation($this->vpEmployee, IncentiveCalculationStatus::PendingVerification);
    $approved = ownCalculation($this->vpEmployee, IncentiveCalculationStatus::Approved);
    $payable = ownCalculation($this->chroEmployee, IncentiveCalculationStatus::Payable);

    expect($this->vp->can('approve', $pending))->toBeFalse()
        ->and($this->vp->can('markPayable', $approved))->toBeFalse()
        ->and($this->vp->can('adjust', $pending))->toBeFalse()
        ->and($this->chro->can('recordPayment', $payable))->toBeFalse()
        ->and($this->vp->can('reject', $pending))->toBeTrue();

    actingAs($this->vp);
    Livewire::test(ViewRecruiterIncentiveCalculation::class, ['record' => $pending->getRouteKey()])->assertActionHidden('approve');
});

test('the service refuses the beneficiary even past the page, and leaves the incentive unchanged', function (Closure $act): void {
    $calculation = ownCalculation($this->vpEmployee, IncentiveCalculationStatus::PendingVerification);

    expect(fn () => $act($calculation->fresh(), $this->vpEmployee))->toThrow(DomainException::class, 'your own incentive')
        ->and($calculation->fresh()->status)->toBe(IncentiveCalculationStatus::PendingVerification)
        ->and($calculation->adjustments()->count())->toBe(0);
})->with([
    'approve' => [fn (RecruiterIncentiveCalculation $c, Employee $actor) => app(IncentiveApprovalService::class)->approve($c, $actor)],
    'adjust' => [fn (RecruiterIncentiveCalculation $c, Employee $actor) => app(IncentiveApprovalService::class)->adjust($c, 10000, 'Raise', $actor)],
]);

test('another approver in scope still approves, marks payable and pays it', function (): void {
    $calculation = ownCalculation($this->vpEmployee, IncentiveCalculationStatus::PendingVerification);

    expect($this->chro->can('approve', $calculation))->toBeTrue();

    $this->service->approve($calculation, $this->chroEmployee);
    $this->service->markPayable($calculation->fresh(), $this->chroEmployee);
    $this->service->pay($calculation->fresh(), 5000, now(), 'REF-1', $this->chroEmployee);

    expect($calculation->fresh()->status)->toBe(IncentiveCalculationStatus::Paid);
});
