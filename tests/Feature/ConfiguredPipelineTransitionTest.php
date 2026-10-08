<?php

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\StageRequirement;
use App\Events\CandidateStageChanged;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\RecruitmentRejectionReason;
use App\Models\RecruitmentRequisition;
use App\Models\RecruitmentStage;
use App\Models\RequisitionPipelineStage;
use App\Models\User;
use App\Services\PipelineTemplateService;
use App\Services\StageTransitionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Event;

/**
 * Applies a pipeline built from $stages to a fresh requisition and returns an application sitting
 * on the first configured stage.
 *
 * @param  array<int, RecruitmentStage>  $stages
 */
function pipelineApplicationOn(array $stages): CandidateApplication
{
    $requisition = RecruitmentRequisition::factory()->create();
    $service = app(PipelineTemplateService::class);
    $template = $service->create(['name' => 'Flow '.uniqid()], array_map(fn (RecruitmentStage $s) => ['recruitment_stage_id' => $s->id], $stages));
    $service->applyToRequisition($requisition, $template);

    $first = $requisition->pipelineStages()->first();
    $application = CandidateApplication::factory()->create([
        'requisition_id' => $requisition->id,
        'current_stage' => $first->milestone,
    ]);
    $application->forceFill(['pipeline_stage_id' => $first->id])->save();

    return $application->fresh();
}

function pipelineSnapshotStage(CandidateApplication $application, RecruitmentStage $stage): RequisitionPipelineStage
{
    return RequisitionPipelineStage::query()->where('requisition_id', $application->requisition_id)->current()->where('code', $stage->code)->firstOrFail();
}

function pipelineEmployeeWithRole(string $role): Employee
{
    $employee = Employee::factory()->create();
    User::factory()->create(['employee_id' => $employee->id])->assignRole($role);

    return $employee;
}

test('moving to the next configured stage updates the milestone, pipeline stage and history', function (): void {
    $screen = RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create();
    $assess = RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create();
    $application = pipelineApplicationOn([$screen, $assess]);
    $actor = Employee::factory()->create();

    app(StageTransitionService::class)->moveToStage($application, pipelineSnapshotStage($application, $assess), $actor, 'Passed screen');

    $history = $application->stageHistory()->first();

    expect($application->fresh()->pipeline_stage_id)->toBe(pipelineSnapshotStage($application, $assess)->id)
        ->and($application->fresh()->current_stage)->toBe(CandidateStage::Screened)
        ->and($history->previous_pipeline_stage_id)->toBe(pipelineSnapshotStage($application, $screen)->id)
        ->and($history->new_pipeline_stage_id)->toBe(pipelineSnapshotStage($application, $assess)->id)
        ->and($history->changed_by)->toBe($actor->id)
        ->and($history->remarks)->toBe('Passed screen')
        ->and($history->is_override)->toBeFalse();
});

test('the default rule allows skipping skippable stages but not a required one', function (): void {
    $screen = RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create();
    $optional = RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create();
    $required = RecruitmentStage::factory()->milestone(CandidateStage::Shortlisted)->required()->create();
    $select = RecruitmentStage::factory()->milestone(CandidateStage::Selected)->create();
    $application = pipelineApplicationOn([$screen, $optional, $required, $select]);

    expect(app(StageTransitionService::class)->allowedNextStages($application)->pluck('code')->all())
        ->toBe([$optional->code, $required->code]);

    app(StageTransitionService::class)->moveToStage($application, pipelineSnapshotStage($application, $select));
})->throws(DomainException::class, 'not an allowed transition');

test('explicit transitions restrict the next stages to the configured ones', function (): void {
    $screen = RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create();
    $shortlist = RecruitmentStage::factory()->milestone(CandidateStage::Shortlisted)->create();
    $select = RecruitmentStage::factory()->milestone(CandidateStage::Selected)->create();
    $screen->allowedNextStages()->attach($select->id);
    $application = pipelineApplicationOn([$screen, $shortlist, $select]);

    expect(app(StageTransitionService::class)->allowedNextStages($application)->pluck('code')->all())->toBe([$select->code]);

    app(StageTransitionService::class)->moveToStage($application, pipelineSnapshotStage($application, $shortlist));
})->throws(DomainException::class, 'not an allowed transition');

test('a transition that requires remarks refuses a move without them', function (): void {
    $screen = RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create();
    $select = RecruitmentStage::factory()->milestone(CandidateStage::Selected)->create();
    $screen->allowedNextStages()->attach($select->id, ['requires_remarks' => true]);
    $application = pipelineApplicationOn([$screen, $select]);

    app(StageTransitionService::class)->moveToStage($application, pipelineSnapshotStage($application, $select));
})->throws(DomainException::class, 'Remarks are required');

test('a stage requirement that is not met blocks the move', function (): void {
    $screen = RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create();
    $offer = RecruitmentStage::factory()->milestone(CandidateStage::OfferInitiated)->create(['requirements' => [StageRequirement::InterviewFeedback->value]]);
    $application = pipelineApplicationOn([$screen, $offer]);

    app(StageTransitionService::class)->moveToStage($application, pipelineSnapshotStage($application, $offer));
})->throws(DomainException::class, 'Interview feedback submitted');

test('nothing moves on from a terminal stage without an override', function (): void {
    $closed = RecruitmentStage::factory()->milestone(CandidateStage::Selected)->terminal()->create();
    $archived = RecruitmentStage::factory()->milestone(CandidateStage::Selected)->terminal()->create();
    $application = pipelineApplicationOn([$closed, $archived]);

    expect(app(StageTransitionService::class)->allowedNextStages($application))->toBeEmpty();

    app(StageTransitionService::class)->moveToStage($application, pipelineSnapshotStage($application, $archived));
})->throws(DomainException::class, 'terminal stage');

test('an authorised override bypasses transition rules and is flagged and audited', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $screen = RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create();
    $required = RecruitmentStage::factory()->milestone(CandidateStage::Shortlisted)->required()->create();
    $select = RecruitmentStage::factory()->milestone(CandidateStage::Selected)->create();
    $application = pipelineApplicationOn([$screen, $required, $select]);
    $manager = pipelineEmployeeWithRole('manager');

    app(StageTransitionService::class)->moveToStage($application, pipelineSnapshotStage($application, $select), $manager, 'Hiring manager fast-tracked', override: true);

    $audit = AuditLog::query()->where('action', 'stage_override')->where('auditable_id', $application->id)->first();

    expect($application->fresh()->current_stage)->toBe(CandidateStage::Selected)
        ->and($application->stageHistory()->first()->is_override)->toBeTrue()
        ->and($audit->getAttribute('changes'))->toMatchArray(['current_stage' => 'selected', 'reason' => 'Hiring manager fast-tracked']);
});

test('an override is refused for an actor without pipeline.override', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $screen = RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create();
    $select = RecruitmentStage::factory()->milestone(CandidateStage::Selected)->create();
    $application = pipelineApplicationOn([$screen, $select]);

    app(StageTransitionService::class)->moveToStage($application, pipelineSnapshotStage($application, $select), pipelineEmployeeWithRole('recruiter'), 'Please', override: true);
})->throws(DomainException::class, 'not allowed to override');

test('an override still needs a reason', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $screen = RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create();
    $select = RecruitmentStage::factory()->milestone(CandidateStage::Selected)->create();
    $application = pipelineApplicationOn([$screen, $select]);

    app(StageTransitionService::class)->moveToStage($application, pipelineSnapshotStage($application, $select), pipelineEmployeeWithRole('manager'), override: true);
})->throws(DomainException::class, 'reason is required');

test('even an override can never move an application back to an earlier milestone', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $screen = RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create();
    $select = RecruitmentStage::factory()->milestone(CandidateStage::Selected)->create();
    $application = pipelineApplicationOn([$screen, $select]);
    app(StageTransitionService::class)->moveToStage($application, pipelineSnapshotStage($application, $select));

    app(StageTransitionService::class)->moveToStage($application->fresh(), pipelineSnapshotStage($application, $screen), pipelineEmployeeWithRole('manager'), 'Undo', override: true);
})->throws(DomainException::class, 'backward');

test('a stage of another requisition pipeline is refused', function (): void {
    $screen = RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create();
    $select = RecruitmentStage::factory()->milestone(CandidateStage::Selected)->create();
    $application = pipelineApplicationOn([$screen, $select]);
    $other = pipelineApplicationOn([$screen, $select]);

    app(StageTransitionService::class)->moveToStage($application, pipelineSnapshotStage($other, $select));
})->throws(DomainException::class, 'not a stage of this application');

test('a canonical transition by a domain service moves the configured stage along with it', function (): void {
    $service = app(PipelineTemplateService::class);
    $requisition = RecruitmentRequisition::factory()->create();
    $service->applyToRequisition($requisition, $service->ensureDefaultTemplate());
    $application = CandidateApplication::factory()->create(['requisition_id' => $requisition->id, 'current_stage' => CandidateStage::Sourced]);

    app(StageTransitionService::class)->transitionTo($application, CandidateStage::InterviewScheduled);

    expect($application->fresh()->pipelineStage->code)->toBe('interview_scheduled')
        ->and($application->stageHistory()->first()->new_pipeline_stage_id)->toBe($application->fresh()->pipeline_stage_id);
});

test('a stage that disallows rejection refuses a reject', function (): void {
    $joined = RecruitmentStage::factory()->milestone(CandidateStage::Joined)->create(['allows_rejection' => false]);
    $application = pipelineApplicationOn([$joined]);

    app(StageTransitionService::class)->reject($application, RecruitmentRejectionReason::factory()->create());
})->throws(DomainException::class, 'does not allow');

test('every stage and status change announces CandidateStageChanged', function (): void {
    Event::fake([CandidateStageChanged::class]);
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Sourced]);

    app(StageTransitionService::class)->transitionTo($application, CandidateStage::Connected);
    app(StageTransitionService::class)->hold($application, null, 'Paused');

    Event::assertDispatched(CandidateStageChanged::class, fn (CandidateStageChanged $e) => $e->newStage === CandidateStage::Connected && ! $e->statusChanged());
    Event::assertDispatched(CandidateStageChanged::class, fn (CandidateStageChanged $e) => $e->newStatus === ApplicationStatus::OnHold && $e->statusChanged());
});
