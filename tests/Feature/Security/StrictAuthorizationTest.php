<?php

use App\Filament\Resources\CandidateJoinings\CandidateJoiningResource;
use App\Models\CandidateJoining;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;

use function Filament\get_authorization_response;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * FAILURE 9 (Phase 8.6, D8.6-027): a Filament action is never available just because a policy
 * method is missing. Local and test runs are strict (a missing rule throws); production denies it
 * (AppServiceProvider's fail-closed gate) without an error.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    actingAs(User::factory()->create()->assignRole('chro'));
});

test('strict authorization is on for test runs and a missing policy method throws', function (): void {
    expect(Filament::getPanel('admin')->isAuthorizationStrict())->toBeTrue()
        ->and(fn () => get_authorization_response('replicate', Department::factory()->create()))->toThrow(LogicException::class, 'replicate');
});

test('outside strict mode a missing policy method is denied, never allowed', function (): void {
    Filament::getPanel('admin')->strictAuthorization(false);
    $department = Department::factory()->create();

    expect(AppServiceProvider::policyLacksAbility('replicate', [$department]))->toBeTrue()
        ->and(get_authorization_response('replicate', $department)->allowed())->toBeFalse()
        // An ability the policy does define still follows the policy.
        ->and(get_authorization_response('update', $department)->allowed())->toBeTrue()
        // Plain permission checks (no model) are untouched.
        ->and(AppServiceProvider::policyLacksAbility('settings.manage', []))->toBeFalse();
});

test('creating a joining by hand needs joining.confirm', function (): void {
    // Before 8.6 the missing create() let any panel user (e.g. the employee role) open this page.
    $employee = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('employee');
    $recruiter = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('recruiter');

    expect($employee->can('create', CandidateJoining::class))->toBeFalse()
        ->and($recruiter->can('create', CandidateJoining::class))->toBeTrue();

    actingAs($employee);
    get(CandidateJoiningResource::getUrl('create'))->assertForbidden();
});
