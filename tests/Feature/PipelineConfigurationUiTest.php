<?php

use App\Enums\CandidateStage;
use App\Enums\StageType;
use App\Filament\Resources\CandidateApplications\Pages\ListCandidateApplications;
use App\Filament\Resources\RecruitmentPipelineTemplates\Pages\CreateRecruitmentPipelineTemplate;
use App\Filament\Resources\RecruitmentPipelineTemplates\Pages\EditRecruitmentPipelineTemplate;
use App\Filament\Resources\RecruitmentPipelineTemplates\Pages\ListRecruitmentPipelineTemplates;
use App\Filament\Resources\RecruitmentRequisitions\Pages\CreateRecruitmentRequisition;
use App\Filament\Resources\RecruitmentStages\Pages\CreateRecruitmentStage;
use App\Filament\Resources\RecruitmentStages\Pages\EditRecruitmentStage;
use App\Filament\Resources\RecruitmentStages\Pages\ListRecruitmentStages;
use App\Filament\Resources\RecruitmentStages\RecruitmentStageResource;
use App\Models\CandidateApplication;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Location;
use App\Models\RecruitmentPipelineTemplate;
use App\Models\RecruitmentRequisition;
use App\Models\RecruitmentStage;
use App\Models\User;
use App\Services\PipelineTemplateService;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

function pipelineActingAs(string $role): User
{
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $user->assignRole($role);
    actingAs($user);

    return $user;
}

test('only users with pipeline.configure can open the stage builder', function (string $role, bool $allowed): void {
    pipelineActingAs($role);

    $this->get(RecruitmentStageResource::getUrl('index'))->assertStatus($allowed ? 200 : 403);
})->with([
    'chro' => ['chro', true],
    'vp hr' => ['vp_hr', true],
    'manager' => ['manager', false],
    'recruiter' => ['recruiter', false],
]);

test('a stage with transitions is created from the form', function (): void {
    pipelineActingAs('chro');
    $next = RecruitmentStage::factory()->milestone(CandidateStage::Shortlisted)->create();

    Livewire::test(CreateRecruitmentStage::class)
        ->fillForm([
            'name' => 'Culture Fit Call',
            'stage_type' => StageType::Screening->value,
            'milestone' => CandidateStage::Screened->value,
            'color' => 'info',
            'sla_hours' => 24,
            'transitions' => [['to_stage_id' => $next->id, 'requires_remarks' => false]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $stage = RecruitmentStage::query()->where('code', 'culture_fit_call')->firstOrFail();

    expect($stage->sla_hours)->toBe(24)
        ->and($stage->allowedNextStages()->pluck('recruitment_stages.id')->all())->toBe([$next->id]);
});

test('a backward transition configured in the form is refused with a message', function (): void {
    pipelineActingAs('chro');
    $stage = RecruitmentStage::factory()->milestone(CandidateStage::Selected)->create();
    $earlier = RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create();

    Livewire::test(EditRecruitmentStage::class, ['record' => $stage->id])
        ->fillForm(['transitions' => [['to_stage_id' => $earlier->id, 'requires_remarks' => false]]])
        ->call('save')
        ->assertNotified('Stage could not be saved');

    expect($stage->allowedNextStages()->count())->toBe(0);
});

test('reordering stages in the table persists the order', function (): void {
    pipelineActingAs('chro');
    [$a, $b] = RecruitmentStage::factory()->count(2)->sequence(['sort_order' => 1], ['sort_order' => 2])->create()->all();

    Livewire::test(ListRecruitmentStages::class)->call('reorderTable', [(string) $b->id, (string) $a->id]);

    expect($b->fresh()->sort_order)->toBe(1)->and($a->fresh()->sort_order)->toBe(2);
});

test('a template is created and edited through the form, bumping its version', function (): void {
    pipelineActingAs('chro');
    $screen = RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create();
    $select = RecruitmentStage::factory()->milestone(CandidateStage::Selected)->create();

    Livewire::test(CreateRecruitmentPipelineTemplate::class)
        ->fillForm(['name' => 'Sales Hiring', 'stages' => [['recruitment_stage_id' => $screen->id, 'sla_hours' => null, 'is_skippable' => null]]])
        ->call('create')
        ->assertHasNoFormErrors();

    $template = RecruitmentPipelineTemplate::query()->where('name', 'Sales Hiring')->firstOrFail();

    Livewire::test(EditRecruitmentPipelineTemplate::class, ['record' => $template->id])
        ->fillForm(['stages' => [
            ['recruitment_stage_id' => $screen->id, 'sla_hours' => 8, 'is_skippable' => '0'],
            ['recruitment_stage_id' => $select->id, 'sla_hours' => null, 'is_skippable' => null],
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($template->fresh()->version)->toBe(2)
        ->and($template->templateStages()->first()->is_skippable)->toBeFalse();
});

test('the clone action copies a template', function (): void {
    pipelineActingAs('chro');
    $template = app(PipelineTemplateService::class)->create(['name' => 'Base'], [['recruitment_stage_id' => RecruitmentStage::factory()->create()->id]]);

    Livewire::test(ListRecruitmentPipelineTemplates::class)
        ->callAction(TestAction::make('clone')->table($template), ['name' => 'Base Copy']);

    expect(RecruitmentPipelineTemplate::query()->where('name', 'Base Copy')->value('cloned_from_id'))->toBe($template->id);
});

test('creating a requisition snapshots the chosen template onto it', function (): void {
    pipelineActingAs('chro');
    $template = app(PipelineTemplateService::class)->create(['name' => 'Campus'], [['recruitment_stage_id' => RecruitmentStage::factory()->create()->id]]);

    Livewire::test(CreateRecruitmentRequisition::class)
        ->fillForm([
            'department_id' => Department::factory()->create()->id,
            'designation_id' => Designation::factory()->create()->id,
            'location_id' => Location::factory()->create()->id,
            'openings' => 2,
            'employment_type' => 'permanent',
            'priority' => 'medium',
            'pipeline_template_id' => $template->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $requisition = RecruitmentRequisition::query()->latest('id')->firstOrFail();

    expect($requisition->pipeline_template_id)->toBe($template->id)
        ->and($requisition->pipelineStages()->count())->toBe(1);
});

test('advancing an application on a configured pipeline only offers the allowed next stages', function (): void {
    pipelineActingAs('chro');
    $screen = RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create();
    $required = RecruitmentStage::factory()->milestone(CandidateStage::Shortlisted)->required()->create();
    $select = RecruitmentStage::factory()->milestone(CandidateStage::Selected)->create();
    $requisition = RecruitmentRequisition::factory()->create();
    $service = app(PipelineTemplateService::class);
    $service->applyToRequisition($requisition, $service->create(['name' => 'Flow'], [
        ['recruitment_stage_id' => $screen->id], ['recruitment_stage_id' => $required->id], ['recruitment_stage_id' => $select->id],
    ]));
    $stages = $requisition->pipelineStages()->get()->keyBy('code');
    $application = CandidateApplication::factory()->create(['requisition_id' => $requisition->id, 'current_stage' => CandidateStage::Screened]);
    $application->forceFill(['pipeline_stage_id' => $stages[$screen->code]->id])->save();

    Livewire::test(ListCandidateApplications::class)
        ->callAction(TestAction::make('advanceStage')->table($application), ['pipeline_stage_id' => $stages[$select->code]->id])
        ->assertHasFormErrors(['pipeline_stage_id']);

    Livewire::test(ListCandidateApplications::class)
        ->callAction(TestAction::make('advanceStage')->table($application), ['pipeline_stage_id' => $stages[$required->code]->id])
        ->assertHasNoFormErrors();

    expect($application->fresh()->pipeline_stage_id)->toBe($stages[$required->code]->id);
});
