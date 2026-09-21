<?php

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\RejectionCategory;
use App\Filament\Resources\CandidateApplications\Pages\ListCandidateApplications;
use App\Filament\Resources\Offers\OfferResource;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\RecruitmentRejectionReason;
use App\Models\User;
use App\Services\StageTransitionService;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $recruiter = Employee::factory()->create();
    $this->application = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');
    actingAs($user);
});

test('the Raise Offer action links to the offer form for an active application', function (): void {
    Livewire::test(ListCandidateApplications::class)
        ->assertActionVisible(TestAction::make('raiseOffer')->table($this->application))
        ->assertActionHasUrl(
            TestAction::make('raiseOffer')->table($this->application),
            OfferResource::getUrl('create', ['application' => $this->application->id]),
        );
});

test('the Raise Offer action is hidden once an offer is accepted or the application is inactive', function (): void {
    $this->application->forceFill(['current_stage' => CandidateStage::OfferAccepted])->save();

    Livewire::test(ListCandidateApplications::class)
        ->assertActionHidden(TestAction::make('raiseOffer')->table($this->application));

    $this->application->forceFill(['current_stage' => CandidateStage::Selected, 'status' => ApplicationStatus::Rejected])->save();

    Livewire::test(ListCandidateApplications::class)
        ->assertActionHidden(TestAction::make('raiseOffer')->table($this->application));
});

test('the Put On Hold action requires remarks', function (): void {
    Livewire::test(ListCandidateApplications::class)
        ->callAction(TestAction::make('hold')->table($this->application), data: ['remarks' => ''])
        ->assertHasFormErrors(['remarks' => 'required']);

    expect($this->application->fresh()->status)->toBe(ApplicationStatus::Active);
});

test('the Put On Hold action puts the application on hold via StageTransitionService', function (): void {
    Livewire::test(ListCandidateApplications::class)
        ->callAction(TestAction::make('hold')->table($this->application), data: ['remarks' => 'Client paused hiring'])
        ->assertHasNoFormErrors();

    expect($this->application->fresh()->status)->toBe(ApplicationStatus::OnHold)
        ->and($this->application->stageHistory()->where('remarks', 'like', '%Client paused hiring%')->exists())->toBeTrue();
});

test('the Reactivate action records the entered remarks', function (): void {
    app(StageTransitionService::class)->hold($this->application, null, 'Waiting');

    Livewire::test(ListCandidateApplications::class)
        ->callAction(TestAction::make('reactivate')->table($this->application->fresh()), data: ['remarks' => 'Hiring resumed'])
        ->assertHasNoFormErrors();

    expect($this->application->fresh()->status)->toBe(ApplicationStatus::Active)
        ->and($this->application->stageHistory()->where('remarks', 'Hiring resumed')->exists())->toBeTrue();
});

test('the Reject action only accepts an active reason', function (): void {
    $inactive = RecruitmentRejectionReason::factory()->create(['is_active' => false]);

    Livewire::test(ListCandidateApplications::class)
        ->callAction(TestAction::make('reject')->table($this->application), data: ['rejection_reason_id' => $inactive->id])
        ->assertHasFormErrors(['rejection_reason_id']);

    expect($this->application->fresh()->status)->toBe(ApplicationStatus::Active);
});

test('reason options list only active reasons grouped by category in category order', function (): void {
    $joining = RecruitmentRejectionReason::factory()->create(['name' => 'Did Not Join', 'category' => RejectionCategory::Joining]);
    $general = RecruitmentRejectionReason::factory()->create(['name' => 'Salary Issue', 'category' => RejectionCategory::General]);
    $inactive = RecruitmentRejectionReason::factory()->create(['name' => 'Retired Reason', 'category' => RejectionCategory::General, 'is_active' => false]);

    $options = RecruitmentRejectionReason::groupedActiveOptions();

    expect(array_keys($options))->toBe(['General', 'Joining'])
        ->and($options['General'])->toBe([$general->id => 'Salary Issue'])
        ->and($options['Joining'])->toBe([$joining->id => 'Did Not Join'])
        ->and($options['General'])->not->toHaveKey($inactive->id);
});
