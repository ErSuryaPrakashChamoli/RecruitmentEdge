<?php

use App\Models\Employee;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('a manager can view a requisition they are named on but not an unrelated one', function (): void {
    $managerEmployee = Employee::factory()->create();
    $manager = User::factory()->create(['employee_id' => $managerEmployee->id]);
    $manager->assignRole('manager');

    $ownRequisition = RecruitmentRequisition::factory()->create(['manager_id' => $managerEmployee->id]);
    $unrelatedRequisition = RecruitmentRequisition::factory()->create();

    expect($manager->can('view', $ownRequisition))->toBeTrue();
    expect($manager->can('view', $unrelatedRequisition))->toBeFalse();
});

test('a recruiter assigned to a requisition can view it via the pivot', function (): void {
    $recruiterEmployee = Employee::factory()->create();
    $recruiter = User::factory()->create(['employee_id' => $recruiterEmployee->id]);
    $recruiter->assignRole('recruiter');

    $requisition = RecruitmentRequisition::factory()->create();
    $requisition->recruiters()->attach($recruiterEmployee->id);

    expect($recruiter->can('view', $requisition))->toBeTrue();
});

test('chro can view and approve any requisition regardless of assignment', function (): void {
    $chro = User::factory()->create();
    $chro->assignRole('chro');

    $requisition = RecruitmentRequisition::factory()->create();

    expect($chro->can('view', $requisition))->toBeTrue()
        ->and($chro->can('approve', $requisition))->toBeTrue();
});

test('a manager without requisitions.approve cannot approve even their own requisition', function (): void {
    $managerEmployee = Employee::factory()->create();
    $manager = User::factory()->create(['employee_id' => $managerEmployee->id]);
    $manager->assignRole('manager');

    $requisition = RecruitmentRequisition::factory()->create(['manager_id' => $managerEmployee->id]);

    expect($manager->can('approve', $requisition))->toBeFalse();
});
