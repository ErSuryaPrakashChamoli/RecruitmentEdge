<?php

use App\Enums\CandidateStage;
use App\Enums\TalentPoolVisibility;
use App\Enums\TimelineEventType;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\RecruitmentRequisition;
use App\Models\TalentPool;
use App\Models\User;
use App\Services\AI\Tools\CandidateTools\GetCandidateTimelineTool;
use App\Services\AI\Tools\CandidateTools\ListTalentPoolsTool;
use App\Services\AI\Tools\JobTools\GetRequisitionPipelineTool;
use App\Services\CandidateTimelineService;
use App\Services\PipelineTemplateService;
use App\Services\TalentPoolService;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->manager = Employee::factory()->create();
    $this->user = User::factory()->create(['employee_id' => $this->manager->id]);
    $this->user->assignRole('manager');
});

test('list_talent_pools returns visible pools and only visible members', function (): void {
    $pool = TalentPool::factory()->visibility(TalentPoolVisibility::Organization)->create(['owner_id' => $this->manager->id, 'criteria' => 'Enterprise sales closers']);
    TalentPool::factory()->visibility(TalentPoolVisibility::Private)->create();
    $mine = CandidateApplication::factory()->create(['recruiter_id' => $this->manager->id])->candidate;
    $theirs = Candidate::factory()->create();
    app(TalentPoolService::class)->addCandidates($pool, [$mine->id, $theirs->id]);
    $tool = app(ListTalentPoolsTool::class);

    $list = $tool->handle([], $this->user);
    $members = $tool->handle(['talent_pool_id' => $pool->id], $this->user);

    expect(collect($list->data['talent_pools'])->pluck('id')->all())->toBe([$pool->id])
        ->and($list->data['talent_pools'][0]['criteria'])->toBe('Enterprise sales closers')
        ->and(collect($members->data['candidates'])->pluck('id')->all())->toBe([$mine->id]);
});

test('get_requisition_pipeline exposes the configured pipeline as structured stage context', function (): void {
    $requisition = RecruitmentRequisition::factory()->create(['manager_id' => $this->manager->id]);
    $pipelines = app(PipelineTemplateService::class);
    $pipelines->applyToRequisition($requisition, $pipelines->ensureDefaultTemplate());
    CandidateApplication::factory()->create(['requisition_id' => $requisition->id, 'recruiter_id' => $this->manager->id, 'current_stage' => CandidateStage::Screened]);

    $result = app(GetRequisitionPipelineTool::class)->handle(['requisition_id' => $requisition->id], $this->user);
    $screened = collect($result->data['configured_pipeline'])->firstWhere('code', 'screened');

    expect($result->data['pipeline_template'])->toBe('Standard Corporate Hiring')
        ->and($screened)->toMatchArray(['milestone' => 'screened', 'type' => 'screening', 'active' => 1]);
});

test('get_candidate_timeline returns the unified timeline including recorded events, without their private text', function (): void {
    $application = CandidateApplication::factory()->create(['recruiter_id' => $this->manager->id]);
    app(CandidateTimelineService::class)->record($application->candidate_id, TimelineEventType::Note, 'Prefers remote', related: ['application' => $application]);

    $result = app(GetCandidateTimelineTool::class)->handle(['application_id' => $application->id], $this->user);

    expect(collect($result->data['timeline'])->pluck('type')->all())->toContain(TimelineEventType::Note->value)
        ->and(json_encode($result->data))->not->toContain('Prefers remote');
});
