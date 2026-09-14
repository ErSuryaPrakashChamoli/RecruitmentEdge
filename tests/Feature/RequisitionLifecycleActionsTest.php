<?php

use App\Enums\CandidateStage;
use App\Enums\RequisitionStatus;
use App\Filament\Resources\RecruitmentRequisitions\Pages\EditRecruitmentRequisition;
use App\Filament\Resources\RecruitmentRequisitions\Pages\ListRecruitmentRequisitions;
use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use App\Filament\Resources\RecruitmentRequisitions\RelationManagers\ApplicationsRelationManager;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $this->managerEmployee = Employee::factory()->create();
    $this->manager = User::factory()->create(['employee_id' => $this->managerEmployee->id]);
    $this->manager->assignRole('manager');

    $this->chro = User::factory()->create();
    $this->chro->assignRole('chro');
});

test('a manager can submit their draft requisition for approval from the table', function (): void {
    $requisition = RecruitmentRequisition::factory()->create(['manager_id' => $this->managerEmployee->id]);

    actingAs($this->manager);

    Livewire::test(ListRecruitmentRequisitions::class)
        ->callAction(TestAction::make('submitForApproval')->table($requisition), data: ['remarks' => null])
        ->assertNotified('Requisition moved to Pending Approval');

    expect($requisition->refresh()->status)->toBe(RequisitionStatus::PendingApproval)
        ->and($requisition->statusHistory()->first()->changed_by)->toBe($this->managerEmployee->id);
});

test('approve and send back are hidden from a user without requisitions.approve', function (): void {
    $requisition = RecruitmentRequisition::factory()->create([
        'manager_id' => $this->managerEmployee->id,
        'status' => RequisitionStatus::PendingApproval,
    ]);

    actingAs($this->manager);

    Livewire::test(ListRecruitmentRequisitions::class)
        ->assertActionHidden(TestAction::make('approve')->table($requisition))
        ->assertActionHidden(TestAction::make('sendBackToDraft')->table($requisition));
});

test('the generic change status dropdown is gone', function (): void {
    $requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::PendingApproval]);

    actingAs($this->chro);

    Livewire::test(ListRecruitmentRequisitions::class)
        ->assertActionDoesNotExist(TestAction::make('changeStatus')->table($requisition));
});

test('a user with requisitions.approve can approve a pending requisition', function (): void {
    $requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::PendingApproval]);

    actingAs($this->chro);

    Livewire::test(ListRecruitmentRequisitions::class)
        ->callAction(TestAction::make('approve')->table($requisition), data: ['remarks' => 'Budget OK']);

    expect($requisition->refresh()->status)->toBe(RequisitionStatus::Approved);
});

test('putting a requisition on hold requires a reason', function (): void {
    $requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open]);

    actingAs($this->chro);

    Livewire::test(ListRecruitmentRequisitions::class)
        ->callAction(TestAction::make('hold')->table($requisition), data: ['remarks' => ''])
        ->assertHasFormErrors(['remarks' => 'required']);

    expect($requisition->refresh()->status)->toBe(RequisitionStatus::Open);

    Livewire::test(ListRecruitmentRequisitions::class)
        ->callAction(TestAction::make('hold')->table($requisition), data: ['remarks' => 'Budget freeze'])
        ->assertHasNoFormErrors();

    expect($requisition->refresh()->status)->toBe(RequisitionStatus::OnHold);
});

test('only the actions valid for the current status are visible', function (): void {
    $requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::OnHold]);

    actingAs($this->chro);

    Livewire::test(ListRecruitmentRequisitions::class)
        ->assertActionVisible(TestAction::make('resume')->table($requisition))
        ->assertActionVisible(TestAction::make('close')->table($requisition))
        ->assertActionVisible(TestAction::make('cancel')->table($requisition))
        ->assertActionHidden(TestAction::make('open')->table($requisition))
        ->assertActionHidden(TestAction::make('hold')->table($requisition))
        ->assertActionHidden(TestAction::make('submitForApproval')->table($requisition));
});

test('lifecycle actions are available on the edit page header', function (): void {
    $requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Approved]);

    actingAs($this->chro);

    Livewire::test(EditRecruitmentRequisition::class, ['record' => $requisition->getRouteKey()])
        ->assertActionHidden('approve')
        ->callAction('open', data: ['remarks' => null])
        ->assertNotified('Requisition moved to Open');

    expect($requisition->refresh()->status)->toBe(RequisitionStatus::Open);
});

test('filled openings count joined and later stages, not earlier ones', function (): void {
    $requisition = RecruitmentRequisition::factory()->create(['openings' => 5]);

    foreach ([CandidateStage::OfferAccepted, CandidateStage::JoiningConfirmed, CandidateStage::Joined, CandidateStage::DocumentsCompleted, CandidateStage::OnboardingCompleted] as $stage) {
        CandidateApplication::factory()->create(['requisition_id' => $requisition->id, 'current_stage' => $stage]);
    }

    expect($requisition->filledOpeningsCount())->toBe(3)
        ->and($requisition->remainingOpenings())->toBe(2)
        ->and(RecruitmentRequisition::query()->withFilledOpeningsCount()->find($requisition->id)->filledOpeningsCount())->toBe(3);
});

test('the table and edit page show requested vs filled positions', function (): void {
    $requisition = RecruitmentRequisition::factory()->create(['openings' => 4]);
    CandidateApplication::factory()->create(['requisition_id' => $requisition->id, 'current_stage' => CandidateStage::Joined]);

    actingAs($this->chro);

    Livewire::test(ListRecruitmentRequisitions::class)
        ->assertTableColumnStateSet('filled_openings_count', '1 / 4', $requisition);

    $this->get(RecruitmentRequisitionResource::getUrl('edit', ['record' => $requisition]))
        ->assertSuccessful()
        ->assertSee('Positions filled: 1 of 4 requested');
});

test('the applications relation manager lists the requisition applications scoped to the viewer hierarchy', function (): void {
    $ownRecruiter = Employee::factory()->reportingTo($this->managerEmployee)->create();
    $outsider = Employee::factory()->create();

    $requisition = RecruitmentRequisition::factory()->create(['manager_id' => $this->managerEmployee->id]);
    $visible = CandidateApplication::factory()->create(['requisition_id' => $requisition->id, 'recruiter_id' => $ownRecruiter->id]);
    $hidden = CandidateApplication::factory()->create(['requisition_id' => $requisition->id, 'recruiter_id' => $outsider->id]);

    actingAs($this->manager);

    Livewire::test(ApplicationsRelationManager::class, [
        'ownerRecord' => $requisition,
        'pageClass' => EditRecruitmentRequisition::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$visible])
        ->assertCanNotSeeTableRecords([$hidden])
        ->assertSee($visible->candidate->full_name)
        ->assertSee($visible->application_code);
});

test('requisition global search matches code, designation and department within hierarchy scope', function (): void {
    $visible = RecruitmentRequisition::factory()->create(['manager_id' => $this->managerEmployee->id]);
    $visible->designation->update(['name' => 'Zephyr Sales Lead']);
    $visible->department->update(['name' => 'Quasar Operations']);

    $hidden = RecruitmentRequisition::factory()->create();
    $hidden->designation->update(['name' => 'Zephyr Hidden Role']);

    actingAs($this->manager);

    expect(RecruitmentRequisitionResource::getGlobalSearchResults($visible->code)->pluck('title')->first())->toContain($visible->code)
        ->and(RecruitmentRequisitionResource::getGlobalSearchResults('Quasar Operations'))->toHaveCount(1)
        ->and(RecruitmentRequisitionResource::getGlobalSearchResults('Zephyr')->pluck('title')->all())->toBe(["{$visible->code} — Zephyr Sales Lead"]);
});
