<?php

use App\Filament\Pages\OrganizationHierarchy;
use App\Models\Employee;
use App\Models\User;
use App\Services\HierarchyService;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('a manager sees their own subtree, scoped to their hierarchy', function (): void {
    $manager = Employee::factory()->create(['first_name' => 'Root', 'last_name' => 'Manager']);
    $recruiter = Employee::factory()->reportingTo($manager)->create(['first_name' => 'Team', 'last_name' => 'Recruiter']);
    $outsider = Employee::factory()->create(['first_name' => 'Outside', 'last_name' => 'Person']);

    $user = User::factory()->create(['employee_id' => $manager->id]);
    $user->assignRole('manager');

    actingAs($user)
        ->get('/admin/organization-hierarchy')
        ->assertSuccessful()
        ->assertSee('Root Manager')
        ->assertSee('Team Recruiter')
        ->assertDontSee('Outside Person');
});

test('a CHRO with hierarchy.view-all sees every root in the org', function (): void {
    $rootOne = Employee::factory()->create(['first_name' => 'First', 'last_name' => 'Root', 'reports_to_id' => null]);
    $rootTwo = Employee::factory()->create(['first_name' => 'Second', 'last_name' => 'Root', 'reports_to_id' => null]);

    $user = User::factory()->create();
    $user->assignRole('chro');

    actingAs($user)
        ->get('/admin/organization-hierarchy')
        ->assertSuccessful()
        ->assertSee('First Root')
        ->assertSee('Second Root');
});

test('treeFor computes correct team sizes', function (): void {
    $manager = Employee::factory()->create();
    $recruiterA = Employee::factory()->reportingTo($manager)->create();
    Employee::factory()->reportingTo($recruiterA)->create();

    $tree = app(HierarchyService::class)->treeFor($manager->id);

    expect($tree['team_size'])->toBe(2)
        ->and($tree['children'][0]['team_size'])->toBe(1);
});

test('a vp_hr can reassign an employee to a new manager', function (): void {
    $vpHrEmployee = Employee::factory()->create();
    $oldManager = Employee::factory()->reportingTo($vpHrEmployee)->create();
    $newManager = Employee::factory()->reportingTo($vpHrEmployee)->create();
    $recruiter = Employee::factory()->reportingTo($oldManager)->create();

    $user = User::factory()->create(['employee_id' => $vpHrEmployee->id]);
    $user->assignRole('vp_hr');
    actingAs($user);

    Livewire::test(OrganizationHierarchy::class)
        ->callAction(TestAction::make('reassignManager')->arguments(['employeeId' => $recruiter->id]), data: ['reports_to_id' => $newManager->id]);

    expect($recruiter->fresh()->reports_to_id)->toBe($newManager->id);
});

test('a recruiter without hierarchy.reassign cannot see the reassign action', function (): void {
    $manager = Employee::factory()->create();
    $recruiter = Employee::factory()->reportingTo($manager)->create();

    $user = User::factory()->create(['employee_id' => $manager->id]);
    $user->assignRole('recruiter');
    actingAs($user);

    Livewire::test(OrganizationHierarchy::class)
        ->assertActionHidden(TestAction::make('reassignManager')->arguments(['employeeId' => $recruiter->id]));
});
