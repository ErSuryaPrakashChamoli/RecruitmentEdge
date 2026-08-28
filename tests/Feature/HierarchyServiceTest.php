<?php

use App\Models\Employee;
use App\Models\User;
use App\Services\HierarchyService;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->hierarchy = app(HierarchyService::class);
});

test('descendant ids include the employee itself and everyone below them', function (): void {
    $chro = Employee::factory()->create();
    $vp = Employee::factory()->reportingTo($chro)->create();
    $manager = Employee::factory()->reportingTo($vp)->create();
    $recruiter = Employee::factory()->reportingTo($manager)->create();

    expect($this->hierarchy->descendantIdsOf($chro->id)->sort()->values()->all())
        ->toBe([$chro->id, $vp->id, $manager->id, $recruiter->id]);

    expect($this->hierarchy->descendantIdsOf($manager->id)->sort()->values()->all())
        ->toBe([$manager->id, $recruiter->id]);

    expect($this->hierarchy->descendantIdsOf($recruiter->id)->all())
        ->toBe([$recruiter->id]);
});

test('moving an employee to a new manager updates the closure table for the whole subtree', function (): void {
    $chro = Employee::factory()->create();
    $vp = Employee::factory()->reportingTo($chro)->create();
    $manager = Employee::factory()->reportingTo($vp)->create();
    $recruiter = Employee::factory()->reportingTo($manager)->create();

    // Move the manager (and its recruiter) to report directly to the CHRO, bypassing the VP.
    $manager->update(['reports_to_id' => $chro->id]);

    expect($this->hierarchy->descendantIdsOf($chro->id)->sort()->values()->all())
        ->toBe([$chro->id, $vp->id, $manager->id, $recruiter->id])
        ->and($this->hierarchy->descendantIdsOf($vp->id)->all())
        ->toBe([$vp->id])
        ->and($this->hierarchy->descendantIdsOf($manager->id)->sort()->values()->all())
        ->toBe([$manager->id, $recruiter->id]);
});

test('a user with hierarchy.view-all sees everyone regardless of position', function (): void {
    $chroEmployee = Employee::factory()->create();
    $otherEmployee = Employee::factory()->create();

    $user = User::factory()->create(['employee_id' => $chroEmployee->id]);
    $user->assignRole('chro');

    expect($this->hierarchy->visibleEmployeeIdsFor($user))->toBeNull();
    expect($this->hierarchy->canView($user, $otherEmployee))->toBeTrue();
});

test('a user without hierarchy.view-all only sees their own subtree', function (): void {
    $manager = Employee::factory()->create();
    $recruiter = Employee::factory()->reportingTo($manager)->create();
    $outsider = Employee::factory()->create();

    $user = User::factory()->create(['employee_id' => $manager->id]);
    $user->assignRole('manager');

    $visible = $this->hierarchy->visibleEmployeeIdsFor($user);

    expect($visible)->not->toBeNull()
        ->and($visible->sort()->values()->all())->toBe([$manager->id, $recruiter->id])
        ->and($this->hierarchy->canView($user, $recruiter))->toBeTrue()
        ->and($this->hierarchy->canView($user, $outsider))->toBeFalse();
});

test('a user with no linked employee sees nothing', function (): void {
    $user = User::factory()->create(['employee_id' => null]);
    $user->assignRole('recruiter');

    expect($this->hierarchy->visibleEmployeeIdsFor($user))->toHaveCount(0);
});
