<?php

use App\Enums\CandidateStage;
use App\Filament\Pages\Pipeline;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\RecruitmentRequisition;
use App\Models\RecruitmentStage;
use App\Models\User;
use App\Services\PipelineTemplateService;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->manager = Employee::factory()->create();
    $user = User::factory()->create(['employee_id' => $this->manager->id]);
    $user->assignRole('manager');
    actingAs($user);
});

/**
 * @param  array<int, RecruitmentStage>  $stages
 */
function boardRequisition(array $stages, Employee $manager): RecruitmentRequisition
{
    $requisition = RecruitmentRequisition::factory()->create(['manager_id' => $manager->id]);
    $service = app(PipelineTemplateService::class);
    $service->applyToRequisition($requisition, $service->create(['name' => 'Board '.uniqid()], array_map(fn (RecruitmentStage $s) => ['recruitment_stage_id' => $s->id], $stages)));

    return $requisition;
}

test('a requisition with a configured pipeline shows its own stages as the board columns', function (): void {
    $screen = RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create(['name' => 'Screening']);
    $tech = RecruitmentStage::factory()->milestone(CandidateStage::Interview1)->create(['name' => 'Technical Interview']);
    $requisition = boardRequisition([$screen, $tech], $this->manager);

    $page = Livewire::test(Pipeline::class)->set('requisitionId', $requisition->id);

    expect(collect($page->instance()->getColumns())->pluck('label')->all())->toBe(['Screening', 'Technical Interview']);
    $page->assertSee("Showing {$requisition->code}'s own pipeline");
});

test('two requisitions with different pipelines each get their own columns', function (): void {
    $a = boardRequisition([RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create(['name' => 'Application Review'])], $this->manager);
    $b = boardRequisition([RecruitmentStage::factory()->milestone(CandidateStage::Sourced)->create(['name' => 'Sourced B']), RecruitmentStage::factory()->milestone(CandidateStage::Selected)->create(['name' => 'Selected B'])], $this->manager);

    $page = Livewire::test(Pipeline::class);

    expect(collect($page->set('requisitionId', $a->id)->instance()->getColumns())->pluck('label')->all())->toBe(['Application Review'])
        ->and(collect($page->set('requisitionId', $b->id)->instance()->getColumns())->pluck('label')->all())->toBe(['Sourced B', 'Selected B']);
});

test('across all requisitions the board keeps the standard stage columns and says why', function (): void {
    boardRequisition([RecruitmentStage::factory()->create(['name' => 'Custom Only'])], $this->manager);

    $page = Livewire::test(Pipeline::class);

    expect(collect($page->instance()->getColumns())->pluck('key')->first())->toBe('sourced')
        ->and(collect($page->instance()->getColumns())->pluck('label')->all())->not->toContain('Custom Only');
    $page->assertSee('Showing the standard stages across all requisitions');
});

test('cards appear under their configured stage column', function (): void {
    $screen = RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create();
    $tech = RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create();
    $requisition = boardRequisition([$screen, $tech], $this->manager);
    $stages = $requisition->pipelineStages()->get()->keyBy('code');
    $application = CandidateApplication::factory()->create(['requisition_id' => $requisition->id, 'recruiter_id' => $this->manager->id, 'current_stage' => CandidateStage::Screened]);
    lifecycleFixture(fn () => $application->forceFill(['pipeline_stage_id' => $stages[$tech->code]->id])->save());

    $page = Livewire::test(Pipeline::class)->set('requisitionId', $requisition->id)->instance();
    [$first, $second] = $page->getColumns();

    expect($page->getCardsFor($first)['total'])->toBe(0)
        ->and($page->getCardsFor($second)['applications']->pluck('id')->all())->toBe([$application->id]);
});

test('dropping a card on a configured column applies the pipeline transition rules', function (): void {
    $screen = RecruitmentStage::factory()->milestone(CandidateStage::Screened)->create();
    $required = RecruitmentStage::factory()->milestone(CandidateStage::Shortlisted)->required()->create();
    $select = RecruitmentStage::factory()->milestone(CandidateStage::Selected)->create();
    $requisition = boardRequisition([$screen, $required, $select], $this->manager);
    $stages = $requisition->pipelineStages()->get()->keyBy('code');
    $application = CandidateApplication::factory()->create(['requisition_id' => $requisition->id, 'recruiter_id' => $this->manager->id, 'current_stage' => CandidateStage::Screened]);

    $page = Livewire::test(Pipeline::class)->set('requisitionId', $requisition->id);

    $page->call('handleSort', $application->id, 0, 'stage-'.$stages[$select->code]->id)->assertNotified('Stage could not be updated');
    expect($application->fresh()->pipeline_stage_id)->toBe($stages[$screen->code]->id);

    $page->call('handleSort', $application->id, 0, 'stage-'.$stages[$required->code]->id)->assertNotified('Stage updated');
    expect($application->fresh()->pipeline_stage_id)->toBe($stages[$required->code]->id)
        ->and($application->fresh()->current_stage)->toBe(CandidateStage::Shortlisted)
        ->and($application->stageHistory()->first()->new_pipeline_stage_id)->toBe($stages[$required->code]->id);
});

test('a requisition outside the viewer\'s hierarchy never supplies board columns', function (): void {
    $hidden = boardRequisition([RecruitmentStage::factory()->create(['name' => 'Secret Stage'])], Employee::factory()->create());

    $page = Livewire::test(Pipeline::class)->set('requisitionId', $hidden->id);

    expect(collect($page->instance()->getColumns())->pluck('label')->all())->not->toContain('Secret Stage');
});
