<?php

use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

use function Pest\Laravel\actingAs;

/**
 * CandidateApplicationForm was restructured into Sections this session (Filament\Schemas\
 * Components\Section, not Filament\Forms\Components\Section — importing the wrong namespace
 * compiles fine but only fatals at render time, per .ai/rules/schemas.md). This is the render-time
 * check that catches that class of bug.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('the candidate application create page renders successfully', function (): void {
    $recruiter = Employee::factory()->create();
    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');

    actingAs($user)->get('/admin/candidate-applications/create')->assertSuccessful();
});

test('the candidate application edit page renders successfully', function (): void {
    $recruiter = Employee::factory()->create();
    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');

    $application = CandidateApplication::factory()->create();

    actingAs($user)->get("/admin/candidate-applications/{$application->id}/edit")->assertSuccessful();
});
