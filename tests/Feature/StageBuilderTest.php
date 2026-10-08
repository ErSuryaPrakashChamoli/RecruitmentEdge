<?php

use App\Enums\CandidateStage;
use App\Enums\StageType;
use App\Models\AuditLog;
use App\Models\RecruitmentStage;
use App\Services\StageConfigurationService;

test('creating a stage slugs its code, maps it to a milestone and audits it', function (): void {
    $stage = app(StageConfigurationService::class)->create([
        'name' => 'Technical Assessment',
        'stage_type' => StageType::Assessment->value,
        'milestone' => CandidateStage::Screened->value,
        'sla_hours' => 48,
        'requirements' => ['resume', 'not-a-requirement'],
    ]);

    expect($stage->code)->toBe('technical_assessment')
        ->and($stage->milestone)->toBe(CandidateStage::Screened)
        ->and($stage->requirements)->toBe(['resume'])
        ->and(AuditLog::query()->where('auditable_type', RecruitmentStage::class)->where('auditable_id', $stage->id)->where('action', 'created')->exists())->toBeTrue();
});

test('a stage code must be unique', function (): void {
    RecruitmentStage::factory()->create(['code' => 'aptitude_test']);

    app(StageConfigurationService::class)->create([
        'name' => 'Aptitude Test',
        'stage_type' => StageType::Assessment->value,
        'milestone' => CandidateStage::Screened->value,
    ]);
})->throws(DomainException::class, 'already exists');

test('a stage must map to a canonical milestone', function (): void {
    app(StageConfigurationService::class)->create([
        'name' => 'Orphan',
        'stage_type' => StageType::Custom->value,
        'milestone' => 'not_a_stage',
    ]);
})->throws(DomainException::class, 'milestone');

test('system stages mirror the canonical pipeline and are seeded idempotently', function (): void {
    $service = app(StageConfigurationService::class);

    $service->ensureSystemStages();
    $service->ensureSystemStages();

    expect(RecruitmentStage::query()->where('is_system', true)->count())->toBe(count(CandidateStage::cases()))
        ->and(RecruitmentStage::query()->where('code', 'interview_1')->first()->is_interview_stage)->toBeTrue();
});

test('a system stage can be renamed but its milestone cannot change', function (): void {
    $service = app(StageConfigurationService::class);
    $stage = $service->ensureSystemStages()->get('screened');

    $service->update($stage, ['name' => 'Phone Screen']);
    expect($stage->fresh()->name)->toBe('Phone Screen');

    $service->update($stage, ['milestone' => CandidateStage::Selected->value]);
})->throws(DomainException::class, 'milestone cannot be changed');

test('deactivating a stage is audited with the old and new value', function (): void {
    $stage = RecruitmentStage::factory()->create();

    app(StageConfigurationService::class)->setActive($stage, false);

    $log = AuditLog::query()->where('auditable_id', $stage->id)->where('auditable_type', RecruitmentStage::class)->where('action', 'updated')->latest('id')->first();

    expect($stage->fresh()->is_active)->toBeFalse()
        ->and($log->old_values)->toMatchArray(['is_active' => 1])
        ->and($log->getAttribute('changes'))->toMatchArray(['is_active' => false]);
});

test('reordering stages rewrites their sort order in the given sequence', function (): void {
    [$first, $second, $third] = RecruitmentStage::factory()->count(3)->create()->all();

    app(StageConfigurationService::class)->reorder([$third->id, $first->id, $second->id]);

    expect(RecruitmentStage::query()->ordered()->pluck('id')->all())->toBe([$third->id, $first->id, $second->id]);
});

test('allowed transitions are stored and audited', function (): void {
    $from = RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create();
    $to = RecruitmentStage::factory()->milestone(CandidateStage::Shortlisted)->create();

    app(StageConfigurationService::class)->syncTransitions($from, [['to_stage_id' => $to->id, 'requires_remarks' => true]]);

    $log = AuditLog::query()->where('action', 'transitions_updated')->where('auditable_id', $from->id)->first();

    expect($from->allowedNextStages()->pluck('recruitment_stages.id')->all())->toBe([$to->id])
        ->and($log->getAttribute('changes'))->toBe(['allowed_next' => [$to->code.' (remarks required)']]);
});

test('a transition may not point to an earlier milestone', function (): void {
    $from = RecruitmentStage::factory()->milestone(CandidateStage::Shortlisted)->create();
    $to = RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create();

    app(StageConfigurationService::class)->syncTransitions($from, [['to_stage_id' => $to->id]]);
})->throws(DomainException::class, 'cannot transition back');
