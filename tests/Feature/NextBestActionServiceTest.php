<?php

use App\Enums\ActionPriority;
use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\InterviewStatus;
use App\Enums\RecruiterActionType;
use App\Enums\RequisitionStatus;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\NextBestAction\NextBestActionService;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->service = app(NextBestActionService::class);
    $this->recruiter = Employee::factory()->create();
    $this->user = User::factory()->create(['employee_id' => $this->recruiter->id])->assignRole('recruiter');
});

test('an unconfirmed interview tomorrow is the top recommendation, owned by the recruiter with a due time', function (): void {
    $application = CandidateApplication::factory()->create(['recruiter_id' => $this->recruiter->id, 'current_stage' => CandidateStage::InterviewScheduled, 'last_activity_at' => now()]);
    $interview = Interview::factory()->create(['candidate_application_id' => $application->id, 'status' => InterviewStatus::Scheduled, 'scheduled_at' => now()->addHours(20)]);

    $top = $this->service->forApplication($application)->first();

    expect($top->type)->toBe(RecruiterActionType::ConfirmInterview)
        ->and($top->priority)->toBe(ActionPriority::High)
        ->and($top->entity->is($interview))->toBeTrue()
        ->and($top->owner->id)->toBe($this->recruiter->id)
        ->and($top->dueAt->equalTo($interview->scheduled_at->copy()->subHours(4)))->toBeTrue()
        ->and($top->reason)->toContain('Scheduled')
        ->and($top->source)->toBe('interview_unconfirmed');
});

test('missing feedback is assigned to the interviewer', function (): void {
    $interviewer = Employee::factory()->create();
    $application = CandidateApplication::factory()->create(['recruiter_id' => $this->recruiter->id, 'last_activity_at' => now()]);
    Interview::factory()->create(['candidate_application_id' => $application->id, 'status' => InterviewStatus::Completed, 'result' => null, 'interviewer_id' => $interviewer->id, 'scheduled_at' => now()->subDay()]);

    $top = $this->service->forApplication($application)->first();

    expect($top->type)->toBe(RecruiterActionType::CollectFeedback)
        ->and($top->owner->id)->toBe($interviewer->id);
});

test('a closed application needs nothing', function (): void {
    $application = CandidateApplication::factory()->create(['status' => ApplicationStatus::Rejected]);

    expect($this->service->forApplication($application)->sole()->priority)->toBe(ActionPriority::Low);
});

test('the user list is hierarchy-scoped, skips low priority work and suggests sourcing for thin pipelines', function (): void {
    CandidateApplication::factory()->create(['recruiter_id' => $this->recruiter->id, 'current_stage' => CandidateStage::Selected, 'last_activity_at' => now()->subDays(10)]);
    CandidateApplication::factory()->create(['current_stage' => CandidateStage::Selected, 'last_activity_at' => now()->subDays(10)]);
    $requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open, 'openings' => 3, 'opening_date' => now()->subDays(60)]);
    $requisition->recruiters()->attach($this->recruiter->id);

    $suggestions = app(NextBestActionService::class)->forUser(User::factory()->create()->assignRole('chro'));
    $own = $this->service->forUser($this->user);

    $ownApplications = $own->filter(fn ($s) => $s->entity instanceof CandidateApplication);

    expect($ownApplications)->toHaveCount(1)
        ->and($ownApplications->first()->entity->recruiter_id)->toBe($this->recruiter->id)
        ->and($suggestions->pluck('type')->contains(RecruiterActionType::SourceCandidates))->toBeTrue()
        ->and($suggestions->every(fn ($s) => $s->priority !== ActionPriority::Low))->toBeTrue();
});
