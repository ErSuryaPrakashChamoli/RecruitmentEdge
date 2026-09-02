<?php

use App\Enums\JoiningStatus;
use App\Enums\RequisitionStatus;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\RecruitmentRequisition;
use App\Models\User;

test('a joining at risk is notified and not duplicated on a second run', function (): void {
    $recruiter = Employee::factory()->create();
    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $application = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);
    CandidateJoining::factory()->create([
        'candidate_application_id' => $application->id,
        'status' => JoiningStatus::Expected,
        'expected_doj' => now()->subDay(),
    ]);

    $this->artisan('notifications:dispatch-alerts')->assertSuccessful();

    expect($user->notifications()->where('data->title', '[Joining] Expected joiner did not join')->count())->toBe(1);

    $this->artisan('notifications:dispatch-alerts')->assertSuccessful();

    expect($user->fresh()->notifications()->where('data->title', '[Joining] Expected joiner did not join')->count())->toBe(1);
});

test('an overdue vacancy notifies the requisition manager', function (): void {
    $manager = Employee::factory()->create();
    $user = User::factory()->create(['employee_id' => $manager->id]);

    RecruitmentRequisition::factory()->create([
        'status' => RequisitionStatus::Open,
        'manager_id' => $manager->id,
        'opening_date' => now()->subDays(45),
    ]);

    $this->artisan('notifications:dispatch-alerts')->assertSuccessful();

    expect($user->notifications()->where('data->title', '[Recruitment] Vacancy ageing exceeded')->count())->toBe(1);
});
