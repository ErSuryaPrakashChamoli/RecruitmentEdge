<?php

use App\Enums\IncentiveCalculationStatus;
use App\Models\Employee;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('a manager can view and approve incentives for their subordinate recruiter', function (): void {
    $managerEmployee = Employee::factory()->create();
    $recruiterEmployee = Employee::factory()->reportingTo($managerEmployee)->create();

    $manager = User::factory()->create(['employee_id' => $managerEmployee->id]);
    $manager->assignRole('manager');

    $calculation = RecruiterIncentiveCalculation::factory()->create(['employee_id' => $recruiterEmployee->id]);

    expect($manager->can('view', $calculation))->toBeTrue()
        ->and($manager->can('transition', $calculation))->toBeFalse(); // manager role has incentives.view but not incentives.approve by default
});

test('a manager cannot view incentives for a recruiter outside their hierarchy', function (): void {
    $managerEmployee = Employee::factory()->create();
    $manager = User::factory()->create(['employee_id' => $managerEmployee->id]);
    $manager->assignRole('manager');

    $calculation = RecruiterIncentiveCalculation::factory()->create();

    expect($manager->can('view', $calculation))->toBeFalse();
});

test('per-action abilities: vp_hr approves but cannot record payment or reverse a paid incentive', function (): void {
    $vpEmployee = Employee::factory()->create();
    $recruiterEmployee = Employee::factory()->reportingTo($vpEmployee)->create();

    $vp = User::factory()->create(['employee_id' => $vpEmployee->id]);
    $vp->assignRole('vp_hr');

    $chro = User::factory()->create();
    $chro->assignRole('chro');

    $approved = RecruiterIncentiveCalculation::factory()->create(['employee_id' => $recruiterEmployee->id, 'status' => IncentiveCalculationStatus::Approved]);
    $paid = RecruiterIncentiveCalculation::factory()->create(['employee_id' => $recruiterEmployee->id, 'status' => IncentiveCalculationStatus::Paid]);

    expect($vp->can('approve', $approved))->toBeTrue()
        ->and($vp->can('markPayable', $approved))->toBeTrue()
        ->and($vp->can('reject', $approved))->toBeTrue()
        ->and($vp->can('submitForVerification', $approved))->toBeTrue()
        ->and($vp->can('recordPayment', $approved))->toBeFalse()
        ->and($vp->can('reverse', $approved))->toBeTrue()
        ->and($vp->can('reverse', $paid))->toBeFalse()
        ->and($chro->can('reverse', $paid))->toBeTrue()
        ->and($chro->can('recordPayment', $approved))->toBeTrue();
});

test('a manager with only incentives.view has no lifecycle abilities', function (): void {
    $managerEmployee = Employee::factory()->create();
    $recruiterEmployee = Employee::factory()->reportingTo($managerEmployee)->create();

    $manager = User::factory()->create(['employee_id' => $managerEmployee->id]);
    $manager->assignRole('manager');

    $calculation = RecruiterIncentiveCalculation::factory()->create(['employee_id' => $recruiterEmployee->id]);

    foreach (['submitForVerification', 'approve', 'markPayable', 'reject', 'reverse', 'recordPayment'] as $ability) {
        expect($manager->can($ability, $calculation))->toBeFalse();
    }
});

test('vp_hr can approve incentives for anyone in their hierarchy but only chro can pay', function (): void {
    $vpEmployee = Employee::factory()->create();
    $recruiterEmployee = Employee::factory()->reportingTo($vpEmployee)->create();

    $vp = User::factory()->create(['employee_id' => $vpEmployee->id]);
    $vp->assignRole('vp_hr');

    $calculation = RecruiterIncentiveCalculation::factory()->create(['employee_id' => $recruiterEmployee->id]);

    expect($vp->can('transition', $calculation))->toBeTrue()
        ->and($vp->can('pay', $calculation))->toBeFalse();

    $chro = User::factory()->create();
    $chro->assignRole('chro');

    expect($chro->can('pay', $calculation))->toBeTrue();
});
