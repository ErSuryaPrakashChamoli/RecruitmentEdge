<?php

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\InterviewStatus;
use App\Enums\RequisitionStatus;
use App\Events\ApplicationMovedToRequisition;
use App\Filament\Resources\CandidateApplications\Pages\EditCandidateApplication;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\RecruitmentRequisition;
use App\Models\RecruitmentStage;
use App\Models\User;
use App\Services\AI\Tools\ActionTools\AssignCandidatesToRecruiterTool;
use App\Services\AI\Tools\ActionTools\MoveCandidatesStageTool;
use App\Services\ApplicationAssignmentService;
use App\Services\PipelineTemplateService;
use App\Services\StageTransitionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->manager = Employee::factory()->create();
    $this->user = User::factory()->create(['employee_id' => $this->manager->id])->assignRole('manager');
    $this->recruiter = Employee::factory()->reportingTo($this->manager)->create();
    $this->requisition = RecruitmentRequisition::factory()->create(['manager_id' => $this->manager->id, 'status' => RequisitionStatus::Open]);
    $this->application = CandidateApplication::factory()->create(['requisition_id' => $this->requisition->id, 'recruiter_id' => $this->recruiter->id, 'current_stage' => CandidateStage::Screened]);
    $this->assignment = app(ApplicationAssignmentService::class);
});

/**
 * A requisition whose pipeline is Screened → (non-skippable) Interview 1 → Selected.
 */
function applicationLifecyclePipeline(RecruitmentRequisition $requisition): void
{
    $stages = [
        RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create(),
        RecruitmentStage::factory()->milestone(CandidateStage::Interview1)->required()->create(),
        RecruitmentStage::factory()->milestone(CandidateStage::Selected)->create(),
    ];
    $service = app(PipelineTemplateService::class);
    $service->applyToRequisition($requisition, $service->create(['name' => 'Lifecycle '.uniqid()], array_map(fn (RecruitmentStage $s) => ['recruitment_stage_id' => $s->id], $stages)));
}

test('an application\'s stage, status and relationships cannot be written outside their services', function (string $attribute, Closure $value): void {
    expect(fn () => $this->application->update([$attribute => $value->call($this)]))->toThrow(LogicException::class, 'can only change through');
})->with([
    'stage' => ['current_stage', fn () => CandidateStage::Selected],
    'status' => ['status', fn () => ApplicationStatus::Rejected],
    'requisition' => ['requisition_id', fn () => RecruitmentRequisition::factory()->create()->id],
    'recruiter' => ['recruiter_id', fn () => Employee::factory()->create()->id],
    'candidate' => ['candidate_id', fn () => CandidateApplication::factory()->create()->candidate_id],
]);

test('a user-initiated canonical move follows the configured pipeline rules', function (): void {
    applicationLifecyclePipeline($this->requisition);
    $application = CandidateApplication::factory()->create(['requisition_id' => $this->requisition->id, 'recruiter_id' => $this->recruiter->id, 'current_stage' => CandidateStage::Screened]);

    expect(fn () => app(StageTransitionService::class)->advance($application->fresh(), CandidateStage::Selected, $this->manager))->toThrow(DomainException::class, 'not an allowed transition')
        ->and(app(StageTransitionService::class)->advance($application->fresh(), CandidateStage::Interview1, $this->manager)->current_stage)->toBe(CandidateStage::Interview1);
});

test('the Copilot move tool is held to the same pipeline rules', function (): void {
    applicationLifecyclePipeline($this->requisition);
    $application = CandidateApplication::factory()->create(['requisition_id' => $this->requisition->id, 'recruiter_id' => $this->recruiter->id, 'current_stage' => CandidateStage::Screened]);

    $result = app(MoveCandidatesStageTool::class)->handle(['application_ids' => [$application->id], 'stage' => CandidateStage::Selected->value], $this->user);

    expect($result->data['entity_ids'])->toBe([])
        ->and($result->data['failed'][0]['reason'])->toContain('not an allowed transition')
        ->and($application->fresh()->current_stage)->not->toBe(CandidateStage::Selected);
});

test('moving to another requisition is explicit, reasoned, audited and announced', function (): void {
    Event::fake([ApplicationMovedToRequisition::class]);
    $destination = RecruitmentRequisition::factory()->create(['manager_id' => $this->manager->id, 'status' => RequisitionStatus::Open]);

    expect(fn () => $this->assignment->moveToRequisition($this->application, $destination, $this->user, ' '))->toThrow(DomainException::class, 'reason');

    $moved = $this->assignment->moveToRequisition($this->application, $destination, $this->user, 'Better fit for the Pune team');
    $audit = AuditLog::query()->where('action', 'application_moved')->sole();

    expect($moved->fresh()->requisition_id)->toBe($destination->id)
        ->and($moved->fresh()->current_stage)->toBe(CandidateStage::Screened)
        ->and($audit->old_values['requisition_id'])->toBe($this->requisition->id)
        ->and($audit->changes['reason'])->toBe('Better fit for the Pune team')
        ->and($moved->stageHistory()->latest('id')->first()->remarks)->toContain("Moved from {$this->requisition->code} to {$destination->code}");
    Event::assertDispatched(ApplicationMovedToRequisition::class, fn ($event) => $event->fromRequisitionId === $this->requisition->id && $event->toRequisitionId === $destination->id);
});

test('an application with an open interview, or to a closed or invisible requisition, cannot be moved', function (): void {
    $closed = RecruitmentRequisition::factory()->create(['manager_id' => $this->manager->id, 'status' => RequisitionStatus::Closed]);
    $invisible = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open]);
    $open = RecruitmentRequisition::factory()->create(['manager_id' => $this->manager->id, 'status' => RequisitionStatus::Open]);
    Interview::factory()->create(['candidate_application_id' => $this->application->id, 'status' => InterviewStatus::Scheduled]);

    expect(fn () => $this->assignment->moveToRequisition($this->application, $closed, $this->user, 'x'))->toThrow(DomainException::class, 'Open requisition you can see')
        ->and(fn () => $this->assignment->moveToRequisition($this->application, $invisible, $this->user, 'x'))->toThrow(DomainException::class, 'Open requisition you can see')
        ->and(fn () => $this->assignment->moveToRequisition($this->application, $open, $this->user, 'x'))->toThrow(DomainException::class, 'open interviews');
});

test('reassigning the recruiter is audited and limited to the actor\'s team', function (): void {
    $teammate = Employee::factory()->reportingTo($this->manager)->create();
    $outsider = Employee::factory()->create();

    $this->assignment->reassignRecruiter($this->application, $teammate, $this->user, 'Workload');

    expect($this->application->fresh()->recruiter_id)->toBe($teammate->id)
        ->and(AuditLog::query()->where('action', 'application_reassigned')->sole()->changes)->toMatchArray(['recruiter_id' => $teammate->id, 'reason' => 'Workload'])
        ->and(fn () => $this->assignment->reassignRecruiter($this->application->fresh(), $outsider, $this->user))->toThrow(DomainException::class, 'your own team');

    app(AssignCandidatesToRecruiterTool::class)->handle(['application_ids' => [$this->application->id], 'recruiter_employee_id' => $this->recruiter->id], $this->user);

    expect($this->application->fresh()->recruiter_id)->toBe($this->recruiter->id)
        ->and(AuditLog::query()->where('action', 'application_reassigned')->count())->toBe(2);
});

test('an interview scheduled after the move was checked, but before it was written, stops the move (Phase 8.9, P89-DQ-009)', function (): void {
    $destination = RecruitmentRequisition::factory()->create(['manager_id' => $this->manager->id, 'status' => RequisitionStatus::Open]);
    $interleaved = false;
    Event::listen(TransactionBeginning::class, function () use (&$interleaved): void {
        if (! $interleaved) {
            $interleaved = true;
            Interview::factory()->create(['candidate_application_id' => $this->application->id, 'status' => InterviewStatus::Scheduled]);
        }
    });

    expect(fn () => $this->assignment->moveToRequisition($this->application, $destination, $this->user, 'Better fit'))->toThrow(DomainException::class, 'open interviews')
        ->and($this->application->fresh()->requisition_id)->toBe($this->requisition->id)
        ->and(AuditLog::query()->where('action', 'application_moved')->exists())->toBeFalse();
});

test('Manager A cannot move or reassign Manager B\'s application', function (): void {
    $managerB = Employee::factory()->create();
    $applicationB = CandidateApplication::factory()->create(['recruiter_id' => $managerB->id]);
    $destination = RecruitmentRequisition::factory()->create(['manager_id' => $this->manager->id, 'status' => RequisitionStatus::Open]);

    expect(fn () => $this->assignment->moveToRequisition($applicationB, $destination, $this->user, 'x'))->toThrow(DomainException::class, 'not allowed')
        ->and(fn () => $this->assignment->reassignRecruiter($applicationB, $this->recruiter, $this->user))->toThrow(DomainException::class, 'not allowed');
});

test('the edit form no longer changes the requisition, candidate or recruiter', function (): void {
    actingAs($this->user);

    Livewire::test(EditCandidateApplication::class, ['record' => $this->application->getRouteKey()])
        ->assertFormFieldIsDisabled('requisition_id')
        ->assertFormFieldIsDisabled('recruiter_id')
        ->assertFormFieldIsDisabled('candidate_id');
});
