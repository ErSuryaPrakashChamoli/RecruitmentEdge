<?php

use App\Filament\Resources\CandidateApplications\CandidateApplicationResource;
use App\Filament\Resources\Candidates\CandidateResource;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('candidate global search finds a candidate by mobile number, scoped to the viewer hierarchy', function (): void {
    $manager = Employee::factory()->create();
    $recruiter = Employee::factory()->reportingTo($manager)->create();
    $outsider = Employee::factory()->create();

    $visible = Candidate::factory()->create(['mobile' => '9998887771']);
    CandidateApplication::factory()->create(['candidate_id' => $visible->id, 'recruiter_id' => $recruiter->id]);

    $hidden = Candidate::factory()->create(['mobile' => '9998887772']);
    CandidateApplication::factory()->create(['candidate_id' => $hidden->id, 'recruiter_id' => $outsider->id]);

    $user = User::factory()->create(['employee_id' => $manager->id]);
    $user->assignRole('manager');
    actingAs($user);

    $visibleResults = CandidateResource::getGlobalSearchResults('9998887771');
    $hiddenResults = CandidateResource::getGlobalSearchResults('9998887772');

    expect($visibleResults->pluck('title'))->toContain($visible->full_name)
        ->and($hiddenResults)->toBeEmpty();
});

test('candidate application global search finds an application by its code', function (): void {
    $recruiter = Employee::factory()->create();
    $application = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');
    actingAs($user);

    $results = CandidateApplicationResource::getGlobalSearchResults($application->application_code);

    expect($results)->not->toBeEmpty()
        ->and($results->first()->title)->toContain($application->application_code);
});
