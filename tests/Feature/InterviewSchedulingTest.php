<?php

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\InterviewResult;
use App\Enums\InterviewStatus;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\InterviewFeedback;
use App\Models\User;
use App\Services\InterviewService;

beforeEach(function (): void {
    $this->service = app(InterviewService::class);
});

function scheduleData(array $overrides = []): array
{
    return [
        'interviewer_id' => Employee::factory()->create()->id,
        'scheduled_at' => now()->addDay(),
        'mode' => 'video_call',
        ...$overrides,
    ];
}

test('scheduling creates a scheduled interview and advances an earlier application to Interview Scheduled', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Shortlisted]);

    $interview = $this->service->schedule($application, scheduleData([
        'location' => 'HQ Room 4',
        'meeting_link' => 'https://meet.example.com/abc',
    ]));

    expect($interview->status)->toBe(InterviewStatus::Scheduled)
        ->and($interview->round_number)->toBe(1)
        ->and($interview->location)->toBe('HQ Room 4')
        ->and($interview->meeting_link)->toBe('https://meet.example.com/abc')
        ->and($application->refresh()->current_stage)->toBe(CandidateStage::InterviewScheduled)
        ->and($application->stageHistory()->where('new_stage', CandidateStage::InterviewScheduled)->exists())->toBeTrue();
});

test('scheduling a later round never moves an application that is already past Interview Scheduled', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Interview1]);
    Interview::factory()->create(['candidate_application_id' => $application->id, 'round_number' => 1, 'status' => InterviewStatus::Completed]);

    $interview = $this->service->schedule($application, scheduleData());

    expect($interview->round_number)->toBe(2)
        ->and($application->refresh()->current_stage)->toBe(CandidateStage::Interview1)
        ->and($application->stageHistory()->count())->toBe(0);
});

test('the round number can be overridden', function (): void {
    $application = CandidateApplication::factory()->create();

    expect($this->service->schedule($application, scheduleData(['round_number' => 3]))->round_number)->toBe(3);
});

test('scheduling an interview for an inactive application is rejected', function (): void {
    $application = CandidateApplication::factory()->create(['status' => ApplicationStatus::Rejected]);

    expect(fn () => $this->service->schedule($application, scheduleData()))->toThrow(DomainException::class, 'not active');

    expect(Interview::query()->count())->toBe(0);
});

test('scheduling notifies both the interviewer and the recruiter, once each', function (): void {
    $interviewer = Employee::factory()->create();
    $interviewerUser = User::factory()->create(['employee_id' => $interviewer->id]);
    $recruiter = Employee::factory()->create();
    $recruiterUser = User::factory()->create(['employee_id' => $recruiter->id]);
    $application = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);

    $this->service->schedule($application, scheduleData(['interviewer_id' => $interviewer->id]));

    expect($interviewerUser->notifications()->count())->toBe(1)
        ->and($recruiterUser->notifications()->count())->toBe(1);

    $selfInterviewing = CandidateApplication::factory()->create(['recruiter_id' => $interviewer->id]);
    $this->service->schedule($selfInterviewing, scheduleData(['interviewer_id' => $interviewer->id]));

    expect($interviewerUser->notifications()->count())->toBe(2);
});

test('rescheduling sets the Rescheduled status, the new time and remarks', function (): void {
    $interview = Interview::factory()->create(['status' => InterviewStatus::Confirmed]);
    $newTime = now()->addDays(4)->startOfMinute();

    $this->service->reschedule($interview, $newTime, 'Interviewer travelling');

    $interview->refresh();
    expect($interview->status)->toBe(InterviewStatus::Rescheduled)
        ->and($interview->scheduled_at->toDateTimeString())->toBe($newTime->toDateTimeString())
        ->and($interview->remarks)->toContain('Interviewer travelling');
});

test('a terminal interview cannot be rescheduled or put on hold', function (string $method): void {
    $interview = Interview::factory()->create(['status' => InterviewStatus::Cancelled]);

    $method === 'reschedule'
        ? $this->service->reschedule($interview, now()->addDay())
        : $this->service->hold($interview, 'Waiting');
})->with(['reschedule', 'hold'])->throws(DomainException::class);

test('holding an interview requires remarks', function (): void {
    $interview = Interview::factory()->create(['status' => InterviewStatus::Scheduled]);

    expect(fn () => $this->service->hold($interview, '  '))->toThrow(DomainException::class, 'Remarks');

    $this->service->hold($interview, 'Requisition paused');

    expect($interview->refresh()->status)->toBe(InterviewStatus::Hold)
        ->and($interview->status->isTerminal())->toBeFalse();
});

test('confirm works from Scheduled or Rescheduled only', function (): void {
    $scheduled = Interview::factory()->create(['status' => InterviewStatus::Scheduled]);
    $rescheduled = Interview::factory()->create(['status' => InterviewStatus::Rescheduled]);
    $onHold = Interview::factory()->create(['status' => InterviewStatus::Hold]);

    $this->service->confirm($scheduled);
    $this->service->confirm($rescheduled);

    expect($scheduled->refresh()->status)->toBe(InterviewStatus::Confirmed)
        ->and($rescheduled->refresh()->status)->toBe(InterviewStatus::Confirmed)
        ->and(fn () => $this->service->confirm($onHold))->toThrow(DomainException::class);
});

test('a rescheduled or held interview can still be completed', function (InterviewStatus $status): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::InterviewScheduled]);
    $interview = Interview::factory()->create(['candidate_application_id' => $application->id, 'status' => $status]);
    InterviewFeedback::factory()->create(['interview_id' => $interview->id]);

    $this->service->complete($interview, InterviewResult::Selected);

    expect($interview->refresh()->status)->toBe(InterviewStatus::Completed);
})->with([InterviewStatus::Rescheduled, InterviewStatus::Hold]);

test('selecting a candidate from an interview that is not completed or was rejected is rejected', function (array $attributes): void {
    $interview = Interview::factory()->create($attributes);

    $this->service->selectCandidate($interview);
})->with([
    'not completed' => [['status' => InterviewStatus::Scheduled]],
    'rejected' => [['status' => InterviewStatus::Completed, 'result' => InterviewResult::Rejected]],
])->throws(DomainException::class);

test('feedback score defaults to the criteria ratings average scaled to 10', function (): void {
    $feedback = InterviewFeedback::factory()->create([
        'score' => null,
        'ratings' => ['technical' => 5, 'communication' => 4, 'problem_solving' => 3, 'culture_fit' => 4, 'bogus' => 5],
    ]);

    expect($feedback->refresh()->score)->toBe('8.0')
        ->and($feedback->ratings)->toBe(['technical' => 5, 'communication' => 4, 'problem_solving' => 3, 'culture_fit' => 4]);
});

test('an explicitly entered feedback score is kept', function (): void {
    $feedback = InterviewFeedback::factory()->create(['score' => 6, 'ratings' => ['technical' => 5]]);

    expect($feedback->refresh()->score)->toBe('6.0');
});
