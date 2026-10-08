<?php

use App\Enums\InterviewResult;
use App\Enums\InterviewStatus;
use App\Events\InterviewCancelled;
use App\Events\InterviewMarkedNoShow;
use App\Filament\Resources\Interviews\Pages\EditInterview;
use App\Filament\Resources\Interviews\Pages\ListInterviews;
use App\Listeners\SendCandidateCommunications;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\InterviewFeedback;
use App\Models\User;
use App\Services\Automation\AutomationEventRegistry;
use App\Services\Communication\CommunicationService;
use App\Services\InterviewFeedbackService;
use App\Services\InterviewService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->manager = Employee::factory()->create();
    $this->managerUser = User::factory()->create(['employee_id' => $this->manager->id])->assignRole('manager');
    $this->interviewer = Employee::factory()->reportingTo($this->manager)->create();
    $this->interviewerUser = User::factory()->create(['employee_id' => $this->interviewer->id])->assignRole('recruiter');
    $this->application = CandidateApplication::factory()->create(['recruiter_id' => $this->manager->id]);
    $this->interview = Interview::factory()->create(['candidate_application_id' => $this->application->id, 'interviewer_id' => $this->interviewer->id, 'status' => InterviewStatus::Scheduled, 'result' => null]);
    $this->feedback = app(InterviewFeedbackService::class);
});

/**
 * @return array<string, string>
 */
function interviewLifecycleFeedback(string $text = 'Clear communicator, strong SQL.'): array
{
    return ['recommendation' => 'recommend', 'feedback' => $text, 'ratings' => ['technical' => 4]];
}

test('an interview\'s status, time and interviewer cannot be written outside InterviewService', function (string $attribute, Closure $value): void {
    expect(fn () => $this->interview->update([$attribute => $value->call($this)]))->toThrow(LogicException::class, 'InterviewService');
})->with([
    'status' => ['status', fn () => InterviewStatus::Cancelled],
    'time' => ['scheduled_at', fn () => now()->addWeek()],
    'interviewer' => ['interviewer_id', fn () => Employee::factory()->create()->id],
]);

test('cancelling from the interviews table needs a reason and goes through the service, so calendar, messages and automation follow', function (): void {
    Event::fake([InterviewCancelled::class]);
    actingAs($this->managerUser);

    Livewire::test(ListInterviews::class)
        ->callTableAction('cancelInterview', $this->interview, ['remarks' => ''])
        ->assertHasTableActionErrors(['remarks' => 'required']);
    Livewire::test(ListInterviews::class)
        ->callTableAction('cancelInterview', $this->interview, ['remarks' => 'Candidate asked to move it']);

    expect($this->interview->fresh()->status)->toBe(InterviewStatus::Cancelled)
        ->and($this->interview->fresh()->remarks)->toContain('Candidate asked to move it');
    Event::assertDispatched(InterviewCancelled::class, fn (InterviewCancelled $event) => $event->interview->is($this->interview) && $event->cause === null);
});

test('a no-show is recorded once through the service and announced for automation', function (): void {
    Event::fake([InterviewMarkedNoShow::class]);
    actingAs($this->managerUser);

    Livewire::test(ListInterviews::class)->callTableAction('noShow', $this->interview);

    expect($this->interview->fresh()->status)->toBe(InterviewStatus::NoShow)
        ->and(fn () => app(InterviewService::class)->markNoShow($this->interview->fresh(), $this->manager))->toThrow(DomainException::class, 'already No Show');
    Event::assertDispatched(InterviewMarkedNoShow::class, fn ($event) => $event->interviewId === $this->interview->id);

    expect(app(AutomationEventRegistry::class)->fromEvent(new InterviewMarkedNoShow($this->interview->id, $this->application->id, null))[0])->toBe('interview.no_show');
});

test('a completed interview cannot be turned into a no-show', function (): void {
    lifecycleFixture(fn () => $this->interview->forceFill(['status' => InterviewStatus::Completed])->save());

    expect(fn () => app(InterviewService::class)->markNoShow($this->interview->fresh(), $this->manager))->toThrow(DomainException::class, 'already Completed');
});

test('an interview cancelled because its application closed is not announced to the candidate on its own', function (): void {
    $this->mock(CommunicationService::class)->shouldNotReceive('sendAutomatic');

    app(SendCandidateCommunications::class)->handleInterviewCancelled(new InterviewCancelled($this->interview, null, 'application_rejected'));
});

test('feedback is always attributed to the assigned interviewer, whoever records it', function (): void {
    $fromManager = $this->feedback->submit($this->interview, interviewLifecycleFeedback(), $this->managerUser);

    expect($fromManager->interviewer_id)->toBe($this->interviewer->id)
        ->and($fromManager->submitted_by)->toBe($this->managerUser->id)
        ->and(fn () => $this->feedback->submit($this->interview, interviewLifecycleFeedback(), $this->interviewerUser))->toThrow(DomainException::class, 'already recorded');
});

test('only the assigned interviewer or someone managing the interview can record feedback', function (): void {
    $stranger = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('recruiter');
    $otherManager = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('manager');

    expect(fn () => $this->feedback->submit($this->interview, interviewLifecycleFeedback(), $stranger))->toThrow(DomainException::class, 'Only the assigned interviewer')
        ->and(fn () => $this->feedback->submit($this->interview, interviewLifecycleFeedback(), $otherManager))->toThrow(DomainException::class, 'Only the assigned interviewer')
        ->and($this->feedback->submit($this->interview, interviewLifecycleFeedback(), $this->interviewerUser)->submitted_by)->toBe($this->interviewerUser->id);
});

test('completing the interview locks its feedback; later changes are audited corrections that keep the original', function (): void {
    $original = $this->feedback->submit($this->interview, interviewLifecycleFeedback('PRIVATE-FEEDBACK-TEXT'), $this->interviewerUser);
    app(InterviewService::class)->complete($this->interview->fresh(), InterviewResult::Selected, $this->manager);

    expect($original->fresh()->isLocked())->toBeTrue()
        ->and(fn () => $this->feedback->submit($this->interview->fresh(), interviewLifecycleFeedback(), $this->managerUser))->toThrow(DomainException::class, 'Use a correction')
        ->and(fn () => $original->fresh()->update(['feedback' => 'rewritten']))->toThrow(LogicException::class, 'InterviewFeedbackService')
        ->and(fn () => $this->feedback->correct($original->fresh(), interviewLifecycleFeedback(), ' ', $this->managerUser))->toThrow(DomainException::class, 'reason');

    $correction = $this->feedback->correct($original->fresh(), ['recommendation' => 'neutral', 'feedback' => 'Recorded against the wrong round'], 'Wrong round', $this->managerUser);
    $audit = AuditLog::query()->where('action', 'interview_feedback_corrected')->sole();

    expect($correction->version)->toBe(2)
        ->and($correction->isLocked())->toBeTrue()
        ->and($original->fresh()->is_current)->toBeFalse()
        ->and($original->fresh()->feedback)->toBe('PRIVATE-FEEDBACK-TEXT')
        ->and($this->interview->fresh()->feedback->pluck('id')->all())->toBe([$correction->id])
        ->and($this->interview->fresh()->allFeedback()->count())->toBe(2)
        ->and($audit->changes['reason'])->toBe('Wrong round')
        ->and(json_encode([$audit->old_values, $audit->changes]))->not->toContain('PRIVATE-FEEDBACK-TEXT');
});

test('the edit form no longer changes the application, interviewer or time', function (): void {
    actingAs($this->managerUser);

    Livewire::test(EditInterview::class, ['record' => $this->interview->getRouteKey()])
        ->assertFormFieldIsDisabled('candidate_application_id')
        ->assertFormFieldIsDisabled('interviewer_id')
        ->assertFormFieldIsDisabled('scheduled_at');
});

test('feedback history keeps every version', function (): void {
    $this->feedback->submit($this->interview, interviewLifecycleFeedback(), $this->interviewerUser);

    expect(InterviewFeedback::query()->where('interview_id', $this->interview->id)->where('is_current', true)->count())->toBe(1);
});
