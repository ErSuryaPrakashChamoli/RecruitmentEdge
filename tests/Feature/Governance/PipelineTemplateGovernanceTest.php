<?php

use App\Enums\CandidateStage;
use App\Enums\RequisitionStatus;
use App\Enums\StageHistoryEvent;
use App\Filament\Resources\RecruitmentRequisitions\Pages\ViewRecruitmentRequisition;
use App\Filament\Resources\RecruitmentRequisitions\RelationManagers\PipelineStagesRelationManager;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\CandidateStageHistory;
use App\Models\Employee;
use App\Models\RecruitmentPipelineTemplate;
use App\Models\RecruitmentPipelineTemplateVersion;
use App\Models\RecruitmentRequisition;
use App\Models\RecruitmentStage;
use App\Models\User;
use App\Services\PipelineTemplateService;
use App\Services\StageConfigurationService;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Phase 8.6 pipeline governance (D8.6-018/019): every template version's stage list is stored;
 * re-applying a template needs pipeline.configure, an open requisition and a reason, and each
 * moved application gets a recorded (non-entry) history row; stage-library edits cannot break a
 * template.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->service = app(PipelineTemplateService::class);
    $this->screen = RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create();
    $this->shortlist = RecruitmentStage::factory()->milestone(CandidateStage::Shortlisted)->create();
    $this->configurer = Employee::factory()->create();
    $this->configurerUser = User::factory()->create(['employee_id' => $this->configurer->id])->assignRole('vp_hr');
    $this->manager = Employee::factory()->create();
    $this->managerUser = User::factory()->create(['employee_id' => $this->manager->id])->assignRole('manager');
});

function governedTemplate(array $stages, string $name): RecruitmentPipelineTemplate
{
    return app(PipelineTemplateService::class)->create(['name' => $name], array_map(fn (RecruitmentStage $stage) => ['recruitment_stage_id' => $stage->id], $stages));
}

test('every template version keeps its stage list, including the one in force before the first change', function (): void {
    $template = governedTemplate([$this->screen, $this->shortlist], 'Versioned');
    $this->service->update($template, [], [['recruitment_stage_id' => $this->screen->id]]);

    $versions = RecruitmentPipelineTemplateVersion::query()->where('pipeline_template_id', $template->id)->orderBy('version')->get();

    expect($versions->pluck('version')->all())->toBe([1, 2])
        ->and(collect($versions[0]->stages)->pluck('code')->all())->toBe([$this->screen->code, $this->shortlist->code])
        ->and(collect($versions[1]->stages)->pluck('code')->all())->toBe([$this->screen->code]);

    // A template defined before versions were stored gets its current list captured on first change.
    $legacy = governedTemplate([$this->screen, $this->shortlist], 'Legacy');
    RecruitmentPipelineTemplateVersion::query()->where('pipeline_template_id', $legacy->id)->toBase()->delete();
    $this->service->update($legacy, [], [['recruitment_stage_id' => $this->shortlist->id]]);

    expect(RecruitmentPipelineTemplateVersion::query()->where('pipeline_template_id', $legacy->id)->orderBy('version')->pluck('version')->all())->toBe([1, 2]);
});

test('FAILURE 7: re-applying a template records every moved application and never adds a stage entry', function (): void {
    $requisition = RecruitmentRequisition::factory()->create();
    $this->service->applyToRequisition($requisition, governedTemplate([$this->screen, $this->shortlist], 'Old'), $this->manager);
    $application = CandidateApplication::factory()->create(['requisition_id' => $requisition->id, 'current_stage' => CandidateStage::Shortlisted]);
    $oldStage = $application->fresh()->pipeline_stage_id;
    $entriesBefore = CandidateStageHistory::query()->where('candidate_application_id', $application->id)->milestoneEntries()->count();
    $configuredBefore = CandidateStageHistory::query()->where('candidate_application_id', $application->id)->pipelineStageEntries()->count();

    $this->service->applyToRequisition($requisition->fresh(), governedTemplate([$this->screen], 'New'), $this->configurer->fresh(), 'Shortlisting merged into screening');

    $remap = CandidateStageHistory::query()->where('candidate_application_id', $application->id)->where('event', StageHistoryEvent::PipelineRemapped)->sole();
    $audit = AuditLog::query()->where('auditable_type', RecruitmentRequisition::class)->where('auditable_id', $requisition->id)->where('action', 'pipeline_reapplied')->sole();

    expect($remap->previous_pipeline_stage_id)->toBe($oldStage)
        ->and($remap->new_pipeline_stage_id)->toBe($application->fresh()->pipeline_stage_id)
        ->and($remap->new_stage)->toBe(CandidateStage::Shortlisted)
        ->and($remap->previous_stage)->toBe(CandidateStage::Shortlisted)
        ->and($remap->changed_by)->toBe($this->configurer->id)
        ->and(CandidateStageHistory::query()->where('candidate_application_id', $application->id)->milestoneEntries()->count())->toBe($entriesBefore)
        ->and(CandidateStageHistory::query()->where('candidate_application_id', $application->id)->pipelineStageEntries()->count())->toBe($configuredBefore)
        ->and($audit->reason)->toBe('Shortlisting merged into screening')
        ->and($audit->changes['applications_remapped'])->toBe(1)
        ->and($audit->user_id)->toBeNull();
});

test('re-applying is refused without pipeline.configure, on a closed requisition, or without a reason', function (): void {
    $requisition = RecruitmentRequisition::factory()->create();
    $this->service->applyToRequisition($requisition, governedTemplate([$this->screen], 'First'), $this->manager);
    $next = governedTemplate([$this->shortlist], 'Second');

    expect(fn () => $this->service->applyToRequisition($requisition->fresh(), $next, $this->manager->fresh(), 'Because'))->toThrow(DomainException::class, 'pipeline.configure')
        ->and(fn () => $this->service->applyToRequisition($requisition->fresh(), $next, $this->configurer->fresh(), ''))->toThrow(DomainException::class, 'reason')
        ->and(fn () => $this->service->applyToRequisition($requisition->fresh(), $next, null, 'System'))->toThrow(DomainException::class, 'pipeline.configure');

    $closed = lifecycleFixture(fn () => RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Closed]));
    $this->service->applyToRequisition($closed, governedTemplate([$this->screen], 'Closed first'));

    expect(fn () => $this->service->applyToRequisition($closed->fresh(), $next, $this->configurer->fresh(), 'Late change'))->toThrow(DomainException::class, 'Closed');
});

test('the apply action on a requisition that already has a pipeline is only offered to pipeline.configure holders', function (): void {
    $requisition = RecruitmentRequisition::factory()->create(['manager_id' => $this->manager->id]);
    $this->service->applyToRequisition($requisition, governedTemplate([$this->screen], 'Applied'), $this->manager);

    actingAs($this->managerUser);
    Livewire::test(PipelineStagesRelationManager::class, ['ownerRecord' => $requisition->fresh(), 'pageClass' => ViewRecruitmentRequisition::class])
        ->assertTableActionHidden('applyTemplate');

    actingAs($this->configurerUser);
    Livewire::test(PipelineStagesRelationManager::class, ['ownerRecord' => $requisition->fresh(), 'pageClass' => ViewRecruitmentRequisition::class])
        ->assertTableActionVisible('applyTemplate');
});

test('a stage-library change that would break a template is refused', function (): void {
    $terminalCandidate = RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create();
    governedTemplate([$terminalCandidate, $this->shortlist], 'Guarded');

    expect(fn () => app(StageConfigurationService::class)->update($terminalCandidate, ['milestone' => CandidateStage::Selected->value]))->toThrow(DomainException::class, 'Guarded')
        ->and($terminalCandidate->fresh()->milestone)->toBe(CandidateStage::Screened);
});
