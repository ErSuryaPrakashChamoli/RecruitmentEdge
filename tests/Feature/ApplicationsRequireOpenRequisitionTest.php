<?php

use App\Enums\Priority;
use App\Enums\RequisitionStatus;
use App\Filament\Resources\CandidateApplications\Pages\CreateCandidateApplication;
use App\Filament\Resources\Candidates\Pages\EditCandidate;
use App\Filament\Resources\Candidates\RelationManagers\ApplicationsRelationManager;
use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $this->user = User::factory()->create();
    $this->user->assignRole('chro');
    actingAs($this->user);

    $this->recruiter = Employee::factory()->create();
    $this->candidate = Candidate::factory()->create();
});

test('only open requisitions visible to the user are offered for new applications', function (): void {
    $managerEmployee = Employee::factory()->create();
    $manager = User::factory()->create(['employee_id' => $managerEmployee->id]);
    $manager->assignRole('manager');

    $openOwn = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open, 'manager_id' => $managerEmployee->id]);
    RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::OnHold, 'manager_id' => $managerEmployee->id]);
    RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open]);

    actingAs($manager);

    expect(RecruitmentRequisitionResource::applicationTargetQuery()->pluck('id')->all())->toBe([$openOwn->id]);
});

test('creating an application against a non-open requisition is rejected', function (): void {
    $closed = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Closed]);

    Livewire::test(CreateCandidateApplication::class)
        ->fillForm([
            'application_date' => now()->toDateString(),
            'candidate_id' => $this->candidate->id,
            'requisition_id' => $closed->id,
            'recruiter_id' => $this->recruiter->id,
            'priority' => Priority::Medium->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['requisition_id']);

    expect(CandidateApplication::query()->count())->toBe(0);
});

test('the create page guard notifies when the requisition is no longer open', function (): void {
    $requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::OnHold]);

    expect(fn () => CreateCandidateApplication::ensureRequisitionAcceptsApplications($requisition->id))
        ->toThrow(Halt::class);

    Notification::assertNotified('Application not created');
});

test('an application can be created against an open requisition', function (): void {
    $open = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open]);

    Livewire::test(CreateCandidateApplication::class)
        ->fillForm([
            'application_date' => now()->toDateString(),
            'candidate_id' => $this->candidate->id,
            'requisition_id' => $open->id,
            'recruiter_id' => $this->recruiter->id,
            'priority' => Priority::Medium->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(CandidateApplication::query()->where('requisition_id', $open->id)->count())->toBe(1);
});

test('the candidate applications relation manager rejects a non-open requisition', function (): void {
    $draft = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Draft]);
    $open = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open]);

    Livewire::test(ApplicationsRelationManager::class, ['ownerRecord' => $this->candidate, 'pageClass' => EditCandidate::class])
        ->callAction(TestAction::make('create')->table(), data: [
            'requisition_id' => $draft->id,
            'recruiter_id' => $this->recruiter->id,
            'priority' => Priority::Medium->value,
        ])
        ->assertHasFormErrors(['requisition_id']);

    expect($this->candidate->applications()->count())->toBe(0);

    Livewire::test(ApplicationsRelationManager::class, ['ownerRecord' => $this->candidate, 'pageClass' => EditCandidate::class])
        ->callAction(TestAction::make('create')->table(), data: [
            'requisition_id' => $open->id,
            'recruiter_id' => $this->recruiter->id,
            'priority' => Priority::Medium->value,
        ])
        ->assertHasNoFormErrors();

    expect($this->candidate->applications()->pluck('requisition_id')->all())->toBe([$open->id]);
});

test('helper text explains an empty requisition list when requisitions await opening', function (): void {
    RecruitmentRequisition::factory()->count(2)->create(['status' => RequisitionStatus::Draft]);

    expect(RecruitmentRequisitionResource::applicationTargetHelperText())->toContain('2 requisition(s) are still Draft or awaiting approval');

    RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open]);

    expect(RecruitmentRequisitionResource::applicationTargetHelperText())->toBe('Only Open requisitions accept new applications.');
});
