<?php

use App\Enums\CandidateStage;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\RecruitmentFollowup;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\StageTransitionService;
use Database\Seeders\RolePermissionSeeder;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('the Command Center dashboard loads successfully for a recruiter with a populated pipeline', function (): void {
    $recruiter = Employee::factory()->create();
    $requisition = RecruitmentRequisition::factory()->create(['openings' => 3, 'opening_date' => now()->subDays(10)]);

    $application = CandidateApplication::factory()->create([
        'recruiter_id' => $recruiter->id,
        'requisition_id' => $requisition->id,
        'application_date' => now(),
    ]);

    app(StageTransitionService::class)->transitionTo($application, CandidateStage::Shortlisted, $recruiter);

    Interview::factory()->create(['candidate_application_id' => $application->id, 'status' => InterviewStatus::Completed]);
    Offer::factory()->create(['candidate_application_id' => $application->id, 'status' => OfferStatus::Released]);
    CandidateJoining::factory()->create(['candidate_application_id' => $application->id, 'status' => JoiningStatus::Expected, 'expected_doj' => now()->addWeek()]);
    RecruitmentFollowup::factory()->create(['recruiter_id' => $recruiter->id, 'followup_date' => now()->subDay()]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('recruiter');

    actingAs($user)->get('/admin')->assertSuccessful();
});

test('the Command Center dashboard loads successfully for a manager with team data', function (): void {
    $manager = Employee::factory()->create();
    Employee::factory()->reportingTo($manager)->create();

    $user = User::factory()->create(['employee_id' => $manager->id]);
    $user->assignRole('manager');

    actingAs($user)->get('/admin')->assertSuccessful();
});

test('the Command Center dashboard loads successfully for a CHRO with organization-wide visibility', function (): void {
    $user = User::factory()->create();
    $user->assignRole('chro');

    actingAs($user)->get('/admin')->assertSuccessful();
});

test('the Command Center dashboard loads successfully with no recruitment data at all', function (): void {
    $user = User::factory()->create();
    $user->assignRole('recruiter');

    actingAs($user)->get('/admin')->assertSuccessful();
});
