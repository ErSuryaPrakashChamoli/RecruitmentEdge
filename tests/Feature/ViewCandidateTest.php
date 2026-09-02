<?php

use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('the candidate 360 view renders with applications and duplicate matches tabs', function (): void {
    $recruiter = Employee::factory()->create();
    $candidate = Candidate::factory()->create();
    CandidateApplication::factory()->create(['candidate_id' => $candidate->id, 'recruiter_id' => $recruiter->id]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');

    actingAs($user)
        ->get("/admin/candidates/{$candidate->id}")
        ->assertSuccessful()
        ->assertSee($candidate->full_name)
        ->assertSee($candidate->candidate_code);
});
