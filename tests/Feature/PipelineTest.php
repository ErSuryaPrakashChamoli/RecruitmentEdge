<?php

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Filament\Pages\Pipeline;
use App\Models\CandidateApplication;
use App\Models\Department;
use App\Models\Employee;
use App\Models\RecruitmentRejectionReason;
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

test('the department filter narrows pipeline cards to requisitions in that department', function (): void {
    $recruiter = Employee::factory()->create();
    $sales = Department::factory()->create();
    $finance = Department::factory()->create();

    $inSales = CandidateApplication::factory()->create([
        'recruiter_id' => $recruiter->id,
        'requisition_id' => RecruitmentRequisition::factory()->create(['department_id' => $sales->id])->id,
        'current_stage' => CandidateStage::Sourced,
    ]);
    $inSales->candidate->update(['full_name' => 'Sales Candidate']);

    $inFinance = CandidateApplication::factory()->create([
        'recruiter_id' => $recruiter->id,
        'requisition_id' => RecruitmentRequisition::factory()->create(['department_id' => $finance->id])->id,
        'current_stage' => CandidateStage::Sourced,
    ]);
    $inFinance->candidate->update(['full_name' => 'Finance Candidate']);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');
    actingAs($user);

    Livewire::test(Pipeline::class)
        ->set('departmentId', $sales->id)
        ->assertSee('Sales Candidate')
        ->assertDontSee('Finance Candidate');
});

test('the status filter defaults to active and can show on-hold applications instead', function (): void {
    $recruiter = Employee::factory()->create();

    $active = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id, 'current_stage' => CandidateStage::Sourced]);
    $active->candidate->update(['full_name' => 'Active Candidate']);

    $held = CandidateApplication::factory()->create([
        'recruiter_id' => $recruiter->id,
        'current_stage' => CandidateStage::Sourced,
        'status' => ApplicationStatus::OnHold,
    ]);
    $held->candidate->update(['full_name' => 'Held Candidate']);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');
    actingAs($user);

    Livewire::test(Pipeline::class)
        ->assertSet('statusFilter', ApplicationStatus::Active->value)
        ->assertSee('Active Candidate')
        ->assertDontSee('Held Candidate')
        ->set('statusFilter', ApplicationStatus::OnHold->value)
        ->assertSee('Held Candidate')
        ->assertDontSee('Active Candidate');
});

test('requisition filter options only include requisitions in the viewer hierarchy', function (): void {
    $manager = Employee::factory()->create();
    $recruiter = Employee::factory()->reportingTo($manager)->create();
    $outsider = Employee::factory()->create();

    $visible = RecruitmentRequisition::factory()->create(['created_by' => $recruiter->id]);
    $hidden = RecruitmentRequisition::factory()->create([
        'created_by' => $outsider->id,
        'reporting_manager_id' => $outsider->id,
        'hiring_manager_id' => $outsider->id,
        'assistant_manager_id' => null,
        'manager_id' => null,
        'vp_hr_id' => null,
    ]);

    $user = User::factory()->create(['employee_id' => $manager->id]);
    $user->assignRole('manager');
    actingAs($user);

    $options = collect(Livewire::test(Pipeline::class)->instance()->requisitionOptions())->pluck('value');

    expect($options)->toContain($visible->id)
        ->not->toContain($hidden->id);
});

test('rejecting or dropping out a card from the board goes through StageTransitionService and removes it from the active board', function (string $action, ApplicationStatus $expectedStatus, string $reasonColumn): void {
    $recruiter = Employee::factory()->create();
    $application = CandidateApplication::factory()->create([
        'recruiter_id' => $recruiter->id,
        'current_stage' => CandidateStage::Screened,
    ]);
    $application->candidate->update(['full_name' => 'Closing Candidate']);
    $reason = RecruitmentRejectionReason::factory()->create();

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');
    actingAs($user);

    Livewire::test(Pipeline::class)
        ->assertSee('Closing Candidate')
        ->callAction(
            TestAction::make($action)->arguments(['applicationId' => $application->id]),
            data: ['reason_id' => $reason->id, 'remarks' => 'Closed from board'],
        )
        ->assertHasNoFormErrors()
        ->assertDontSee('Closing Candidate');

    $application->refresh();

    expect($application->status)->toBe($expectedStatus)
        ->and($application->{$reasonColumn})->toBe($reason->id)
        ->and($application->current_stage)->toBe(CandidateStage::Screened)
        ->and($application->stageHistory()->where('remarks', 'Closed from board')->exists())->toBeTrue();
})->with([
    'reject' => ['rejectApplication', ApplicationStatus::Rejected, 'rejection_reason_id'],
    'dropout' => ['dropoutApplication', ApplicationStatus::Dropout, 'dropout_reason_id'],
]);

test('a board reject action refuses an inactive reason', function (): void {
    $recruiter = Employee::factory()->create();
    $application = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);
    $inactive = RecruitmentRejectionReason::factory()->create(['is_active' => false]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');
    actingAs($user);

    Livewire::test(Pipeline::class)
        ->callAction(
            TestAction::make('rejectApplication')->arguments(['applicationId' => $application->id]),
            data: ['reason_id' => $inactive->id],
        )
        ->assertHasFormErrors(['reason_id']);

    expect($application->fresh()->status)->toBe(ApplicationStatus::Active);
});

test('a column list link opens the applications list filtered by that stage via the filters query key', function (): void {
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $user->assignRole('chro');
    actingAs($user);

    $page = Livewire::test(Pipeline::class)->instance();
    $column = collect($page->getColumns())->first();

    parse_str((string) parse_url($page->getColumnListUrl($column['key']), PHP_URL_QUERY), $query);

    expect($query)->not->toHaveKey('tableFilters')
        ->and($query['filters']['current_stage']['value'])->toBe($column['stages'][0]->value);
});
