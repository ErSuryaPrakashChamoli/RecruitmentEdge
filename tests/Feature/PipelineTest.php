<?php

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Filament\Pages\Pipeline;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('the pipeline board renders and only shows hierarchy-visible applications', function (): void {
    $manager = Employee::factory()->create();
    $ownRecruiter = Employee::factory()->reportingTo($manager)->create();
    $outsider = Employee::factory()->create();

    $visible = CandidateApplication::factory()->create([
        'recruiter_id' => $ownRecruiter->id,
        'current_stage' => CandidateStage::Shortlisted,
    ]);
    $visible->candidate->update(['full_name' => 'Visible Candidate']);

    $hidden = CandidateApplication::factory()->create([
        'recruiter_id' => $outsider->id,
        'current_stage' => CandidateStage::Shortlisted,
    ]);
    $hidden->candidate->update(['full_name' => 'Hidden Candidate']);

    $user = User::factory()->create(['employee_id' => $manager->id]);
    $user->assignRole('manager');

    actingAs($user)
        ->get('/admin/pipeline')
        ->assertSuccessful()
        ->assertSee('Visible Candidate')
        ->assertDontSee('Hidden Candidate');
});

test('moving a card to a new stage writes a stage history row via StageTransitionService', function (): void {
    $recruiter = Employee::factory()->create();
    $application = CandidateApplication::factory()->create([
        'recruiter_id' => $recruiter->id,
        'current_stage' => CandidateStage::Sourced,
    ]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');

    actingAs($user);

    Livewire::test(Pipeline::class)
        ->callAction(
            TestAction::make('moveApplication')->arguments(['applicationId' => $application->id]),
            data: ['stage' => CandidateStage::Shortlisted->value],
        );

    expect($application->fresh()->current_stage)->toBe(CandidateStage::Shortlisted)
        ->and($application->fresh()->stageHistory()->where('new_stage', CandidateStage::Shortlisted->value)->exists())->toBeTrue();
});

test('dragging a card between single-stage columns writes a real stage history row via StageTransitionService', function (): void {
    $recruiter = Employee::factory()->create();
    $application = CandidateApplication::factory()->create([
        'recruiter_id' => $recruiter->id,
        'current_stage' => CandidateStage::Sourced,
    ]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');
    actingAs($user);

    Livewire::test(Pipeline::class)->call('handleSort', $application->id, 0, 'contacted');

    expect($application->fresh()->current_stage)->toBe(CandidateStage::ContactAttempted)
        ->and($application->fresh()->stageHistory()->where('new_stage', CandidateStage::ContactAttempted->value)->exists())->toBeTrue();
});

test('dragging into a multi-stage column is a no-op since the target stage is ambiguous', function (): void {
    $recruiter = Employee::factory()->create();
    $application = CandidateApplication::factory()->create([
        'recruiter_id' => $recruiter->id,
        'current_stage' => CandidateStage::Shortlisted,
    ]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');
    actingAs($user);

    Livewire::test(Pipeline::class)->call('handleSort', $application->id, 0, 'interview');

    expect($application->fresh()->current_stage)->toBe(CandidateStage::Shortlisted);
});

test('a user without transitionStage permission cannot drag a card', function (): void {
    $manager = Employee::factory()->create();
    $recruiter = Employee::factory()->reportingTo($manager)->create();
    $application = CandidateApplication::factory()->create([
        'recruiter_id' => $recruiter->id,
        'current_stage' => CandidateStage::Sourced,
    ]);

    Role::findOrCreate('no-transition')->syncPermissions(['candidates.viewAny']);
    $user = User::factory()->create(['employee_id' => $manager->id]);
    $user->assignRole('no-transition');
    actingAs($user);

    Livewire::test(Pipeline::class)->call('handleSort', $application->id, 0, 'contacted')->assertStatus(403);

    expect($application->fresh()->current_stage)->toBe(CandidateStage::Sourced);
});

test('the pipeline summary reflects real open positions and in-pipeline counts', function (): void {
    $recruiter = Employee::factory()->create();
    CandidateApplication::factory()->count(2)->create(['recruiter_id' => $recruiter->id, 'status' => ApplicationStatus::Active]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');
    actingAs($user);

    $summary = Livewire::test(Pipeline::class)->instance()->getSummary();

    expect($summary['in_pipeline'])->toBeGreaterThanOrEqual(2);
});

test('the requisition filter narrows pipeline cards to that requisition only', function (): void {
    $recruiter = Employee::factory()->create();
    $requisitionA = RecruitmentRequisition::factory()->create();
    $requisitionB = RecruitmentRequisition::factory()->create();

    $inA = CandidateApplication::factory()->create([
        'recruiter_id' => $recruiter->id,
        'requisition_id' => $requisitionA->id,
        'current_stage' => CandidateStage::Sourced,
    ]);
    $inA->candidate->update(['full_name' => 'Requisition A Candidate']);

    $inB = CandidateApplication::factory()->create([
        'recruiter_id' => $recruiter->id,
        'requisition_id' => $requisitionB->id,
        'current_stage' => CandidateStage::Sourced,
    ]);
    $inB->candidate->update(['full_name' => 'Requisition B Candidate']);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');
    actingAs($user);

    Livewire::test(Pipeline::class)
        ->set('requisitionId', $requisitionA->id)
        ->assertSee('Requisition A Candidate')
        ->assertDontSee('Requisition B Candidate');
});
