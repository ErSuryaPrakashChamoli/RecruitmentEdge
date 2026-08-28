<?php

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('a manager can view their own subordinate but not an unrelated employee', function (): void {
    $manager = Employee::factory()->create();
    $subordinate = Employee::factory()->reportingTo($manager)->create();
    $unrelated = Employee::factory()->create();

    $user = User::factory()->create(['employee_id' => $manager->id]);
    $user->assignRole('manager');

    expect($user->can('view', $subordinate))->toBeTrue();
    expect($user->can('view', $unrelated))->toBeFalse();
});

test('only users with users.manage can create or update employees', function (): void {
    $manager = Employee::factory()->create();
    $subordinate = Employee::factory()->reportingTo($manager)->create();

    $managerUser = User::factory()->create(['employee_id' => $manager->id]);
    $managerUser->assignRole('manager');

    $chroUser = User::factory()->create();
    $chroUser->assignRole('chro');

    expect($managerUser->can('create', Employee::class))->toBeFalse()
        ->and($managerUser->can('update', $subordinate))->toBeFalse()
        ->and($chroUser->can('create', Employee::class))->toBeTrue()
        ->and($chroUser->can('update', $subordinate))->toBeTrue();
});

test('chro can view any employee regardless of hierarchy position', function (): void {
    $chroUser = User::factory()->create();
    $chroUser->assignRole('chro');

    $farAwayEmployee = Employee::factory()->create();

    expect($chroUser->can('view', $farAwayEmployee))->toBeTrue();
});
