<?php

use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('a manager can view and transition applications owned by their subordinate recruiter', function (): void {
    $managerEmployee = Employee::factory()->create();
    $recruiterEmployee = Employee::factory()->reportingTo($managerEmployee)->create();

    $manager = User::factory()->create(['employee_id' => $managerEmployee->id]);
    $manager->assignRole('manager');

    $application = CandidateApplication::factory()->create(['recruiter_id' => $recruiterEmployee->id]);

    expect($manager->can('view', $application))->toBeTrue()
        ->and($manager->can('transitionStage', $application))->toBeTrue();
});

test('a manager cannot view an application owned by a recruiter outside their hierarchy', function (): void {
    $managerEmployee = Employee::factory()->create();
    $manager = User::factory()->create(['employee_id' => $managerEmployee->id]);
    $manager->assignRole('manager');

    $application = CandidateApplication::factory()->create();

    expect($manager->can('view', $application))->toBeFalse();
});

test('a recruiter without pipeline.transition permission cannot advance stages', function (): void {
    $recruiterEmployee = Employee::factory()->create();
    $recruiter = User::factory()->create(['employee_id' => $recruiterEmployee->id]);
    $recruiter->assignRole('recruiter');
    Role::findByName('recruiter')->revokePermissionTo('pipeline.transition');

    $application = CandidateApplication::factory()->create(['recruiter_id' => $recruiterEmployee->id]);

    expect($recruiter->can('transitionStage', $application))->toBeFalse();
});
