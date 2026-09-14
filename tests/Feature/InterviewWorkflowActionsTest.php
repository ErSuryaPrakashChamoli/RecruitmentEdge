<?php

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\InterviewResult;
use App\Enums\InterviewStatus;
use App\Filament\Resources\CandidateApplications\Pages\ViewCandidateApplication;
use App\Filament\Resources\CandidateApplications\RelationManagers\InterviewsRelationManager;
use App\Filament\Resources\Interviews\Pages\CreateInterview;
use App\Filament\Resources\Interviews\Pages\EditInterview;
use App\Filament\Resources\Interviews\Pages\ListInterviews;
use App\Filament\Resources\Interviews\RelationManagers\FeedbackRelationManager;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\InterviewFeedback;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $this->actor = User::factory()->create();
    $this->actor->assignRole('chro');
    actingAs($this->actor);
});

test('the create interview page schedules through the service and advances the stage', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Screened]);

    Livewire::test(CreateInterview::class)
        ->fillForm([
            'candidate_application_id' => $application->id,
            'interviewer_id' => Employee::factory()->create()->id,
            'scheduled_at' => now()->addDay(),
            'mode' => 'in_person',
            'location' => 'Office',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect($application->interviews()->sole()->round_number)->toBe(1)
        ->and($application->refresh()->current_stage)->toBe(CandidateStage::InterviewScheduled);
});

test('the create interview page shows a notification for an inactive application', function (): void {
    $application = CandidateApplication::factory()->create(['status' => ApplicationStatus::Dropout]);

    Livewire::test(CreateInterview::class)
        ->fillForm([
            'candidate_application_id' => $application->id,
            'interviewer_id' => Employee::factory()->create()->id,
            'scheduled_at' => now()->addDay(),
            'mode' => 'phone',
        ])
        ->call('create')
        ->assertNotified('Interview could not be scheduled');

    expect(Interview::query()->count())->toBe(0);
});

test('the edit interview page and its feedback ratings render', function (): void {
    $interview = Interview::factory()->create();
    InterviewFeedback::factory()->create(['interview_id' => $interview->id, 'ratings' => ['technical' => 5]]);

    $this->get("/admin/interviews/{$interview->id}/edit")
        ->assertSuccessful()
        ->assertSee('Meeting Link');

    Livewire::test(FeedbackRelationManager::class, [
        'ownerRecord' => $interview,
        'pageClass' => EditInterview::class,
    ])->assertSee('Technical 5/5');
});

test('rescheduling from the table uses the Rescheduled status', function (): void {
    $interview = Interview::factory()->create(['status' => InterviewStatus::Scheduled]);

    Livewire::test(ListInterviews::class)
        ->callAction(TestAction::make('reschedule')->table($interview), data: ['scheduled_at' => now()->addDays(2), 'remarks' => 'Clash']);

    expect($interview->fresh()->status)->toBe(InterviewStatus::Rescheduled);
});

test('a rescheduled interview can be confirmed from the table', function (): void {
    $interview = Interview::factory()->create(['status' => InterviewStatus::Rescheduled]);

    Livewire::test(ListInterviews::class)
        ->assertActionVisible(TestAction::make('confirm')->table($interview))
        ->callAction(TestAction::make('confirm')->table($interview));

    expect($interview->fresh()->status)->toBe(InterviewStatus::Confirmed);
});

test('holding an interview from the table requires remarks', function (): void {
    $interview = Interview::factory()->create(['status' => InterviewStatus::Scheduled]);

    Livewire::test(ListInterviews::class)
        ->callAction(TestAction::make('hold')->table($interview), data: ['remarks' => ''])
        ->assertHasFormErrors(['remarks' => 'required']);

    Livewire::test(ListInterviews::class)
        ->callAction(TestAction::make('hold')->table($interview), data: ['remarks' => 'Panel unavailable']);

    expect($interview->fresh()->status)->toBe(InterviewStatus::Hold);
});

test('feedback criteria ratings are captured from the table and default the overall score', function (): void {
    $interview = Interview::factory()->create(['status' => InterviewStatus::Scheduled]);

    Livewire::test(ListInterviews::class)
        ->callAction(TestAction::make('addFeedback')->table($interview), data: [
            'interviewer_id' => Employee::factory()->create()->id,
            'ratings' => ['technical' => '4', 'communication' => '4', 'problem_solving' => '2', 'culture_fit' => '2'],
            'recommendation' => 'neutral',
            'feedback' => 'Mixed.',
        ]);

    $feedback = InterviewFeedback::query()->where('interview_id', $interview->id)->sole();

    expect($feedback->ratings)->toBe(['technical' => 4, 'communication' => 4, 'problem_solving' => 2, 'culture_fit' => 2])
        ->and($feedback->score)->toBe('6.0');
});

test('select candidate is offered only for a completed, non-rejected interview and moves the application to Selected', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Interview2]);
    $completed = Interview::factory()->create([
        'candidate_application_id' => $application->id,
        'status' => InterviewStatus::Completed,
        'result' => InterviewResult::Selected,
    ]);
    $rejected = Interview::factory()->create(['status' => InterviewStatus::Completed, 'result' => InterviewResult::Rejected]);
    $pending = Interview::factory()->create(['status' => InterviewStatus::Scheduled]);

    Livewire::test(ListInterviews::class)
        ->assertActionHidden(TestAction::make('selectCandidate')->table($rejected))
        ->assertActionHidden(TestAction::make('selectCandidate')->table($pending))
        ->callAction(TestAction::make('selectCandidate')->table($completed));

    expect($application->refresh()->current_stage)->toBe(CandidateStage::Selected);
});

test('select candidate is hidden without the pipeline.transition permission', function (): void {
    $interview = Interview::factory()->create(['status' => InterviewStatus::Completed, 'result' => InterviewResult::Selected]);

    $this->actor->roles->each(fn ($role) => $role->revokePermissionTo('pipeline.transition'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Livewire::test(ListInterviews::class)
        ->assertActionHidden(TestAction::make('selectCandidate')->table($interview));
});

test('the candidate 360 schedule action captures round, location and meeting link and advances the stage', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Sourced]);

    Livewire::test(ViewCandidateApplication::class, ['record' => $application->getRouteKey()])
        ->callAction(TestAction::make('scheduleInterview'), data: [
            'round_number' => 2,
            'interviewer_id' => Employee::factory()->create()->id,
            'scheduled_at' => now()->addDay(),
            'mode' => 'video_call',
            'location' => 'Remote',
            'meeting_link' => 'https://meet.example.com/xyz',
        ])
        ->assertHasNoFormErrors();

    $interview = $application->interviews()->sole();

    expect($interview->round_number)->toBe(2)
        ->and($interview->meeting_link)->toBe('https://meet.example.com/xyz')
        ->and($application->refresh()->current_stage)->toBe(CandidateStage::InterviewScheduled);
});

test('the candidate 360 select candidate action selects from the latest completed interview', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::FinalInterview]);
    Interview::factory()->create([
        'candidate_application_id' => $application->id,
        'round_number' => 3,
        'status' => InterviewStatus::Completed,
        'result' => InterviewResult::Selected,
    ]);

    Livewire::test(ViewCandidateApplication::class, ['record' => $application->getRouteKey()])
        ->callAction(TestAction::make('selectCandidate'));

    expect($application->refresh()->current_stage)->toBe(CandidateStage::Selected);
});

test('the candidate 360 select candidate action is hidden without a completed interview', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::InterviewScheduled]);
    Interview::factory()->create(['candidate_application_id' => $application->id, 'status' => InterviewStatus::Scheduled]);

    Livewire::test(ViewCandidateApplication::class, ['record' => $application->getRouteKey()])
        ->assertActionHidden(TestAction::make('selectCandidate'));
});

test('an interview can be scheduled from the application interviews tab', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Shortlisted]);

    Livewire::test(InterviewsRelationManager::class, [
        'ownerRecord' => $application,
        'pageClass' => ViewCandidateApplication::class,
    ])
        ->callAction(TestAction::make('scheduleInterview')->table(), data: [
            'interviewer_id' => Employee::factory()->create()->id,
            'scheduled_at' => now()->addDay(),
            'mode' => 'phone',
        ])
        ->assertHasNoFormErrors();

    expect($application->interviews()->count())->toBe(1)
        ->and($application->refresh()->current_stage)->toBe(CandidateStage::InterviewScheduled);
});
