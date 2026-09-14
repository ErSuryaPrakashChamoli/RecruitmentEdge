<?php

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\DocumentStatus;
use App\Enums\JoiningStatus;
use App\Filament\Resources\CandidateJoinings\Pages\EditCandidateJoining;
use App\Filament\Resources\CandidateJoinings\Pages\ListCandidateJoinings;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('chro');
    actingAs($user);
});

test('documents and onboarding can be completed in order from the joining table', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Joined]);
    $joining = CandidateJoining::factory()->create(['candidate_application_id' => $application->id, 'status' => JoiningStatus::Joined]);

    Livewire::test(ListCandidateJoinings::class)
        ->assertActionHidden(TestAction::make('markOnboardingCompleted')->table($joining))
        ->callAction(TestAction::make('markDocumentsCompleted')->table($joining))
        ->assertNotified('Documents marked as completed');

    expect($joining->refresh()->documents_status)->toBe(DocumentStatus::Verified)
        ->and($application->refresh()->current_stage)->toBe(CandidateStage::DocumentsCompleted);

    Livewire::test(ListCandidateJoinings::class)
        ->assertActionHidden(TestAction::make('markDocumentsCompleted')->table($joining))
        ->callAction(TestAction::make('markOnboardingCompleted')->table($joining));

    expect($application->refresh()->current_stage)->toBe(CandidateStage::OnboardingCompleted);
});

test('post-join milestone actions are hidden until the candidate has joined', function (): void {
    $joining = CandidateJoining::factory()->create(['status' => JoiningStatus::Confirmed]);

    Livewire::test(ListCandidateJoinings::class)
        ->assertActionHidden(TestAction::make('markDocumentsCompleted')->table($joining))
        ->assertActionHidden(TestAction::make('markOnboardingCompleted')->table($joining));
});

test('a joining service rule violation shows a notification instead of an error page', function (): void {
    $application = CandidateApplication::factory()->create(['status' => ApplicationStatus::OnHold]);
    $joining = CandidateJoining::factory()->create(['candidate_application_id' => $application->id, 'status' => JoiningStatus::Expected]);

    Livewire::test(ListCandidateJoinings::class)
        ->callAction(TestAction::make('confirmJoining')->table($joining))
        ->assertNotified('Joining could not be updated');

    expect($joining->refresh()->status)->toBe(JoiningStatus::Expected);
});

test('no-show and dropout require a reason', function (string $action): void {
    $joining = CandidateJoining::factory()->create(['status' => JoiningStatus::Expected]);

    Livewire::test(ListCandidateJoinings::class)
        ->callAction(TestAction::make($action)->table($joining), data: ['reason_id' => null])
        ->assertHasFormErrors(['reason_id' => 'required']);

    expect($joining->refresh()->status)->toBe(JoiningStatus::Expected);
})->with(['markNoShow', 'markDropout']);

test('documents status is editable on the joining form', function (): void {
    $joining = CandidateJoining::factory()->create(['documents_status' => DocumentStatus::Pending]);

    Livewire::test(EditCandidateJoining::class, ['record' => $joining->getRouteKey()])
        ->fillForm(['documents_status' => DocumentStatus::Submitted->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($joining->refresh()->documents_status)->toBe(DocumentStatus::Submitted);
});
