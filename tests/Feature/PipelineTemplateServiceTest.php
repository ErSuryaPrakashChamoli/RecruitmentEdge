<?php

use App\Enums\CandidateStage;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\CandidateStageHistory;
use App\Models\RecruitmentPipelineTemplate;
use App\Models\RecruitmentRequisition;
use App\Models\RecruitmentStage;
use App\Models\RequisitionPipelineStage;
use App\Services\PipelineTemplateService;

/**
 * @param  array<int, RecruitmentStage>  $stages
 */
function pipelineTemplateOf(array $stages, string $name = 'Tech Hiring'): RecruitmentPipelineTemplate
{
    return app(PipelineTemplateService::class)->create(
        ['name' => $name],
        array_map(fn (RecruitmentStage $stage) => ['recruitment_stage_id' => $stage->id], $stages),
    );
}

test('a template stores its stages in the given order', function (): void {
    $screen = RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create();
    $assess = RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create();
    $select = RecruitmentStage::factory()->milestone(CandidateStage::Selected)->create();

    $template = pipelineTemplateOf([$screen, $assess, $select]);

    expect($template->slug)->toBe('tech-hiring')
        ->and($template->version)->toBe(1)
        ->and($template->templateStages()->pluck('recruitment_stage_id')->all())->toBe([$screen->id, $assess->id, $select->id]);
});

test('template stages must follow the canonical milestone order', function (): void {
    $select = RecruitmentStage::factory()->milestone(CandidateStage::Selected)->create();
    $screen = RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create();

    pipelineTemplateOf([$select, $screen]);
})->throws(DomainException::class, 'canonical pipeline order');

test('only terminal stages may follow a terminal stage', function (): void {
    $closed = RecruitmentStage::factory()->milestone(CandidateStage::Selected)->terminal()->create();
    $offer = RecruitmentStage::factory()->milestone(CandidateStage::OfferReleased)->create();

    pipelineTemplateOf([$closed, $offer]);
})->throws(DomainException::class, 'terminal stage');

test('an inactive stage cannot be added to a template', function (): void {
    pipelineTemplateOf([RecruitmentStage::factory()->inactive()->create()]);
})->throws(DomainException::class, 'inactive');

test('a stage cannot appear twice in one template', function (): void {
    $stage = RecruitmentStage::factory()->create();

    pipelineTemplateOf([$stage, $stage]);
})->throws(DomainException::class, 'only once');

test('changing the stage list bumps the version and audits the before and after stages', function (): void {
    $screen = RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create();
    $select = RecruitmentStage::factory()->milestone(CandidateStage::Selected)->create();
    $template = pipelineTemplateOf([$screen]);

    app(PipelineTemplateService::class)->update($template, [], [
        ['recruitment_stage_id' => $screen->id, 'sla_hours' => 24],
        ['recruitment_stage_id' => $select->id],
    ]);

    $log = AuditLog::query()->where('action', 'stages_updated')->where('auditable_id', $template->id)->first();

    expect($template->fresh()->version)->toBe(2)
        ->and($log->old_values)->toBe(['stages' => [$screen->code]])
        ->and($log->getAttribute('changes'))->toBe(['stages' => [$screen->code.' [sla 24h]', $select->code]]);
});

test('saving an unchanged stage list keeps the version', function (): void {
    $stage = RecruitmentStage::factory()->create();
    $template = pipelineTemplateOf([$stage]);

    app(PipelineTemplateService::class)->update($template, ['name' => 'Renamed'], [['recruitment_stage_id' => $stage->id]]);

    expect($template->fresh()->version)->toBe(1)->and($template->fresh()->name)->toBe('Renamed');
});

test('cloning copies the stages and overrides into a new non-default template', function (): void {
    $stage = RecruitmentStage::factory()->create();
    $original = pipelineTemplateOf([$stage]);
    app(PipelineTemplateService::class)->update($original, [], [['recruitment_stage_id' => $stage->id, 'sla_hours' => 12, 'is_skippable' => false]]);

    $clone = app(PipelineTemplateService::class)->clone($original->fresh(), 'Tech Hiring (Copy)');

    $row = $clone->templateStages()->first();

    expect($clone->cloned_from_id)->toBe($original->id)
        ->and($clone->is_default)->toBeFalse()
        ->and($row->recruitment_stage_id)->toBe($stage->id)
        ->and($row->sla_hours)->toBe(12)
        ->and($row->is_skippable)->toBeFalse();
});

test('only one template is ever the default', function (): void {
    $service = app(PipelineTemplateService::class);
    $first = $service->ensureDefaultTemplate();
    $second = pipelineTemplateOf([RecruitmentStage::factory()->create()], 'Sales Hiring');

    $service->setDefault($second);

    expect(RecruitmentPipelineTemplate::query()->where('is_default', true)->pluck('id')->all())->toBe([$second->id])
        ->and($first->fresh()->is_default)->toBeFalse();
});

test('the default template cannot be deactivated', function (): void {
    $service = app(PipelineTemplateService::class);

    $service->setActive($service->ensureDefaultTemplate(), false);
})->throws(DomainException::class, 'default template cannot be deactivated');

test('applying a template snapshots its stages and transitions onto the requisition and audits it', function (): void {
    $screen = RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create(['sla_hours' => 10]);
    $select = RecruitmentStage::factory()->milestone(CandidateStage::Selected)->create();
    $screen->allowedNextStages()->attach($select->id, ['requires_remarks' => true]);
    $template = pipelineTemplateOf([$screen, $select]);
    $requisition = RecruitmentRequisition::factory()->create();

    $snapshot = app(PipelineTemplateService::class)->applyToRequisition($requisition, $template);

    $requisition->refresh();

    expect($snapshot->pluck('code')->all())->toBe([$screen->code, $select->code])
        ->and($snapshot->first()->sla_hours)->toBe(10)
        ->and($snapshot->first()->transitions)->toBe([['code' => $select->code, 'requires_remarks' => true]])
        ->and($requisition->pipeline_template_id)->toBe($template->id)
        ->and($requisition->pipeline_template_version)->toBe(1)
        ->and(AuditLog::query()->where('action', 'pipeline_applied')->where('auditable_id', $requisition->id)->exists())->toBeTrue();
});

test('editing a template or its stages afterwards does not change an applied requisition pipeline', function (): void {
    $screen = RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create(['name' => 'Screen']);
    $select = RecruitmentStage::factory()->milestone(CandidateStage::Selected)->create();
    $template = pipelineTemplateOf([$screen]);
    $requisition = RecruitmentRequisition::factory()->create();
    app(PipelineTemplateService::class)->applyToRequisition($requisition, $template);

    $screen->update(['name' => 'Renamed Screen']);
    app(PipelineTemplateService::class)->update($template, [], [['recruitment_stage_id' => $screen->id], ['recruitment_stage_id' => $select->id]]);

    expect($requisition->pipelineStages()->pluck('name')->all())->toBe(['Screen'])
        ->and($requisition->fresh()->pipeline_template_version)->toBe(1);
});

test('a requisition snapshot cannot be edited in place', function (): void {
    $requisition = RecruitmentRequisition::factory()->create();
    app(PipelineTemplateService::class)->applyToRequisition($requisition, pipelineTemplateOf([RecruitmentStage::factory()->create()]));

    $requisition->pipelineStages()->first()->update(['name' => 'Tampered']);
})->throws(DomainException::class, 'immutable');

test('re-applying a template supersedes the old snapshot and remaps applications without losing history', function (): void {
    $screen = RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create();
    $shortlist = RecruitmentStage::factory()->milestone(CandidateStage::Shortlisted)->create();
    $requisition = RecruitmentRequisition::factory()->create();
    $service = app(PipelineTemplateService::class);
    $service->applyToRequisition($requisition, pipelineTemplateOf([$screen, $shortlist], 'Old'));
    $oldShortlist = $requisition->pipelineStages()->where('code', $shortlist->code)->first();
    $application = CandidateApplication::factory()->create([
        'requisition_id' => $requisition->id,
        'current_stage' => CandidateStage::Shortlisted,
    ]);
    $application->forceFill(['pipeline_stage_id' => $oldShortlist->id])->save();
    $history = CandidateStageHistory::query()->create([
        'candidate_application_id' => $application->id,
        'previous_stage' => CandidateStage::Screened,
        'new_stage' => CandidateStage::Shortlisted,
        'new_pipeline_stage_id' => $oldShortlist->id,
    ]);

    $service->applyToRequisition($requisition, pipelineTemplateOf([$screen], 'New'));

    $current = $requisition->pipelineStages()->get();

    expect($oldShortlist->fresh()->superseded_at)->not->toBeNull()
        ->and($current->pluck('code')->all())->toBe([$screen->code])
        ->and($application->fresh()->pipeline_stage_id)->toBe($current->first()->id)
        ->and($history->fresh()->new_pipeline_stage_id)->toBe($oldShortlist->id)
        ->and(RequisitionPipelineStage::query()->where('requisition_id', $requisition->id)->count())->toBe(3);
});

test('an inactive template cannot be applied', function (): void {
    $template = pipelineTemplateOf([RecruitmentStage::factory()->create()]);
    $template->update(['is_active' => false]);

    app(PipelineTemplateService::class)->applyToRequisition(RecruitmentRequisition::factory()->create(), $template);
})->throws(DomainException::class, 'inactive');

test('the backfill gives legacy requisitions and applications the default pipeline without touching history', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Interview1]);
    $history = CandidateStageHistory::query()->create([
        'candidate_application_id' => $application->id,
        'previous_stage' => CandidateStage::Sourced,
        'new_stage' => CandidateStage::Interview1,
    ]);

    $first = app(PipelineTemplateService::class)->assignDefaultPipelines();
    $second = app(PipelineTemplateService::class)->assignDefaultPipelines();

    $application->refresh();

    expect($first)->toBe(['requisitions' => 1, 'applications' => 0])
        ->and($second)->toBe(['requisitions' => 0, 'applications' => 0])
        ->and($application->pipelineStage->code)->toBe('interview_1')
        ->and($application->current_stage)->toBe(CandidateStage::Interview1)
        ->and($history->fresh()->new_pipeline_stage_id)->toBeNull()
        ->and($application->requisition->pipelineStages()->count())->toBe(count(CandidateStage::cases()));
});
