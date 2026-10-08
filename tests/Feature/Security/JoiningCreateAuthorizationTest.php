<?php

use App\Filament\Resources\CandidateJoinings\CandidateJoiningResource;
use App\Filament\Resources\CandidateJoinings\Pages\CreateCandidateJoining;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

/**
 * SEC-86-I-01: creating a joining record by hand needs joining.confirm, by an explicit policy rule
 * (Filament allows any ability whose policy method is missing).
 *
 * Production readiness (PR-03): this test comes from the production-line hotfix 599f0c5, which is not
 * an ancestor of the release candidate; the rule it proves is in the candidate by content.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->withoutVite();
});

test('a user without joining.confirm can neither create joinings nor open the create page', function (): void {
    $role = Role::findOrCreate('joining_create_probe', 'web');
    $role->givePermissionTo(Permission::findOrCreate('candidates.viewAny', 'web'));
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole($role);
    actingAs($user);

    expect(CandidateJoiningResource::canCreate())->toBeFalse();
    $this->get(CreateCandidateJoining::getUrl())->assertForbidden();
});

test('a recruiter, who confirms joinings, may create one by hand', function (): void {
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('recruiter');
    actingAs($user);

    expect(CandidateJoiningResource::canCreate())->toBeTrue();
    $this->get(CreateCandidateJoining::getUrl())->assertOk();
});
