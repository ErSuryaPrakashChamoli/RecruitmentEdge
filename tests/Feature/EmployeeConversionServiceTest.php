<?php

use App\Enums\JoiningStatus;
use App\Models\Candidate;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\User;
use App\Services\EmployeeConversionService;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->service = app(EmployeeConversionService::class);
    $this->hr = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('chro');
});

test('converting a joined candidate creates an employee linked back to the candidate', function (): void {
    $candidate = Candidate::factory()->create(['full_name' => 'Rahul Sharma']);
    $joining = CandidateJoining::factory()->create(['status' => JoiningStatus::Joined]);
    lifecycleFixture(fn () => $joining->candidateApplication->update(['candidate_id' => $candidate->id]));

    $employee = $this->service->convert($joining->fresh(['candidateApplication']), $this->hr);

    expect($employee->candidate_id)->toBe($candidate->id)
        ->and($employee->first_name)->toBe('Rahul')
        ->and($employee->last_name)->toBe('Sharma')
        ->and($employee->email)->toBe($candidate->email)
        ->and($candidate->fresh()->employee->id)->toBe($employee->id);
});

test('a candidate not yet joined cannot be converted', function (): void {
    $joining = CandidateJoining::factory()->create(['status' => JoiningStatus::Expected]);

    $this->service->convert($joining, $this->hr);
})->throws(DomainException::class);

test('a candidate cannot be converted twice', function (): void {
    $joining = CandidateJoining::factory()->create(['status' => JoiningStatus::Joined]);

    $this->service->convert($joining, $this->hr);

    $this->service->convert($joining->fresh(), $this->hr);
})->throws(DomainException::class, 'already been converted');
