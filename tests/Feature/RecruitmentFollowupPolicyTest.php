<?php

use App\Models\Employee;
use App\Models\RecruitmentFollowup;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('a manager can view follow-ups owned by their subordinate recruiter', function (): void {
    $managerEmployee = Employee::factory()->create();
    $recruiterEmployee = Employee::factory()->reportingTo($managerEmployee)->create();

    $manager = User::factory()->create(['employee_id' => $managerEmployee->id]);
    $manager->assignRole('manager');

    $followup = RecruitmentFollowup::factory()->create(['recruiter_id' => $recruiterEmployee->id]);

    expect($manager->can('view', $followup))->toBeTrue();
});

test('a manager cannot view a follow-up owned by a recruiter outside their hierarchy', function (): void {
    $managerEmployee = Employee::factory()->create();
    $manager = User::factory()->create(['employee_id' => $managerEmployee->id]);
    $manager->assignRole('manager');

    $followup = RecruitmentFollowup::factory()->create();

    expect($manager->can('view', $followup))->toBeFalse();
});

test('chro can view any follow-up', function (): void {
    $chro = User::factory()->create();
    $chro->assignRole('chro');

    $followup = RecruitmentFollowup::factory()->create();

    expect($chro->can('view', $followup))->toBeTrue();
});
