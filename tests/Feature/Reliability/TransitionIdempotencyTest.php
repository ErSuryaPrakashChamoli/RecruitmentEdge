<?php

use App\Enums\ApplicationStatus;
use App\Enums\AutomationActionStatus;
use App\Enums\CandidateStage;
use App\Enums\FollowupType;
use App\Enums\InterviewStatus;
use App\Enums\OfferStatus;
use App\Enums\RequisitionStatus;
use App\Jobs\PublishJobDistributionJob;
use App\Jobs\SyncInterviewCalendarJob;
use App\Models\AutomationExecution;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\CandidateTimelineEvent;
use App\Models\Employee;
use App\Models\HiringMemoryRecord;
use App\Models\Interview;
use App\Models\Interviewer;
use App\Models\Offer;
use App\Models\OfferStatusHistory;
use App\Models\RecruitmentFollowup;
use App\Models\RecruitmentRejectionReason;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\Automation\Actions\Handlers\AddTimelineEventAction;
use App\Services\Automation\Actions\Handlers\CreateFollowupAction;
use App\Services\Automation\Actions\Handlers\HoldApplicationAction;
use App\Services\Automation\AutomationContext;
use App\Services\Distribution\JobBoardRegistry;
use App\Services\Distribution\JobDistributionService;
use App\Services\Integrations\Calendar\CalendarSyncService;
use App\Services\Intelligence\EvidenceRecorder;
use App\Services\Intelligence\HiringMemoryService;
use App\Services\InterviewService;
use App\Services\OfferService;
use App\Services\StageTransitionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Phase 8.7 (D8.7-005/026): a transition decides on the current status under a row lock, so a
 * stale copy (a double click, a second tab, the portal and a recruiter at once) is refused rather
 * than repeating the transition; jobs act on current state; captures are all-or-nothing.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->releaser = Employee::factory()->create();
    User::factory()->create(['employee_id' => $this->releaser->id])->assignRole('chro');
});

test('a second acceptance from a stale copy of the offer is refused, so the joining is created once', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::OfferReleased]);
    $offer = Offer::factory()->create(['candidate_application_id' => $application->id, 'status' => OfferStatus::Released]);
    $staleCopy = Offer::query()->find($offer->id);

    app(OfferService::class)->moveTo($offer, OfferStatus::Accepted, $this->releaser);

    expect(fn () => app(OfferService::class)->moveTo($staleCopy, OfferStatus::Accepted, $this->releaser))->toThrow(DomainException::class, 'from Accepted to Accepted');
    expect(OfferStatusHistory::query()->where('offer_id', $offer->id)->where('to_status', OfferStatus::Accepted)->count())->toBe(1)
        ->and(CandidateJoining::query()->where('candidate_application_id', $application->id)->count())->toBe(1);
});

test('a stale copy of an interview cannot cancel or complete it again', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Shortlisted]);
    $interview = app(InterviewService::class)->schedule($application, ['interviewer_id' => Interviewer::factory()->create()->employee_id, 'scheduled_at' => now()->addDays(2), 'mode' => 'phone']);
    $staleCopy = Interview::query()->find($interview->id);

    app(InterviewService::class)->cancel($interview, 'Candidate asked to cancel');

    expect(fn () => app(InterviewService::class)->cancel($staleCopy, 'Clicked again'))->toThrow(DomainException::class, 'already Cancelled')
        ->and(fn () => app(InterviewService::class)->confirm(Interview::query()->find($interview->id)))->toThrow(DomainException::class)
        ->and($interview->fresh()->remarks)->not->toContain('Clicked again');
});

test('a repeated interview reschedule to the same time alerts the interviewer once', function (): void {
    $interviewer = Interviewer::factory()->create();
    User::factory()->create(['employee_id' => $interviewer->employee_id]);
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Shortlisted]);
    $interview = app(InterviewService::class)->schedule($application, ['interviewer_id' => $interviewer->employee_id, 'scheduled_at' => now()->addDays(2), 'mode' => 'phone']);
    $newTime = now()->addDays(4)->startOfHour();

    app(InterviewService::class)->reschedule($interview, $newTime);
    app(InterviewService::class)->reschedule(Interview::query()->find($interview->id), $newTime);

    $user = User::query()->where('employee_id', $interviewer->employee_id)->sole();

    expect($user->notifications()->where('data->title', 'like', '%Interview rescheduled%')->count())->toBe(1);
});

test('a calendar job decides what to do from the interview as it is now, and one waits per interview', function (): void {
    $interview = Interview::factory()->create(['status' => InterviewStatus::Scheduled]);
    Interview::query()->whereKey($interview->id)->update(['status' => InterviewStatus::Cancelled->value]);
    $calendar = Mockery::mock(CalendarSyncService::class);
    $calendar->shouldReceive('sync')->once()->withArgs(fn (Interview $synced, string $action) => $synced->id === $interview->id && $action === 'cancel')->andReturnNull();
    app()->instance(CalendarSyncService::class, $calendar);

    app()->call([new SyncInterviewCalendarJob($interview->id, 'create'), 'handle']);

    config(['queue.default' => 'database']);
    SyncInterviewCalendarJob::dispatch($interview->id, 'create');
    SyncInterviewCalendarJob::dispatch($interview->id, 'update');

    expect(DB::table('jobs')->where('queue', 'integrations')->count())->toBe(1);
});

test('a publish job that runs after its posting was paused lists nothing', function (): void {
    $requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open, 'manager_id' => Employee::factory()->create()->id]);
    $service = app(JobDistributionService::class);
    $posting = $service->savePosting($requisition, ['title' => 'Sales Executive', 'description' => str_repeat('Drive enterprise sales. ', 5)]);
    Queue::fake();
    $service->publish($posting, ['xml_feed']);
    $service->pause($posting->fresh());
    $distribution = $posting->distributions()->sole();

    app()->call([new PublishJobDistributionJob($distribution->id, 'publish'), 'handle'], ['boards' => app(JobBoardRegistry::class)]);

    expect($distribution->fresh()->attempts)->toBe(0)
        ->and($distribution->fresh()->external_url)->toBeNull();
});

test('a Hiring Memory capture interrupted part-way leaves nothing, so the retry captures it completely', function (): void {
    $application = CandidateApplication::factory()->create();
    app(StageTransitionService::class)->reject($application, RecruitmentRejectionReason::factory()->create());
    HiringMemoryRecord::query()->delete();

    $failingEvidence = Mockery::mock(EvidenceRecorder::class);
    $failingEvidence->shouldReceive('record')->andThrow(new RuntimeException('worker died'));
    app()->instance(EvidenceRecorder::class, $failingEvidence);
    app()->forgetInstance(HiringMemoryService::class);

    expect(fn () => app(HiringMemoryService::class)->captureRejection($application->fresh()))->toThrow(RuntimeException::class)
        ->and(HiringMemoryRecord::query()->count())->toBe(0);

    app()->forgetInstance(EvidenceRecorder::class);
    app()->forgetInstance(HiringMemoryService::class);
    $record = app(HiringMemoryService::class)->captureRejection($application->fresh());

    expect($record)->not->toBeNull()
        ->and($record->evidence()->count())->toBeGreaterThan(0);
});

test('re-running an automation action after a crash neither duplicates its follow-up or note nor fails a hold that happened', function (): void {
    $application = CandidateApplication::factory()->create(['recruiter_id' => Employee::factory()->create()->id, 'status' => ApplicationStatus::Active]);
    $execution = AutomationExecution::factory()->create();
    $context = AutomationContext::for($application, 'candidate.stage_changed');
    $run = fn (string $handler, array $config, int $position) => app($handler)->execute($config, AutomationContext::for($application->fresh(), 'candidate.stage_changed'), $execution, $position);

    foreach ([1, 2] as $attempt) {
        $followup = $run(CreateFollowupAction::class, ['followup_type' => FollowupType::cases()[0]->value, 'due_in_hours' => 4, 'remarks' => 'Call back'], 0);
        $note = $run(AddTimelineEventAction::class, ['title' => 'Checked by automation'], 1);
        $hold = $run(HoldApplicationAction::class, ['remarks' => 'Waiting for budget'], 2);
    }

    expect(RecruitmentFollowup::query()->where('candidate_application_id', $application->id)->count())->toBe(1)
        ->and(CandidateTimelineEvent::query()->where('title', 'Checked by automation')->count())->toBe(1)
        ->and($hold->status)->toBe(AutomationActionStatus::Completed)
        ->and($followup->status)->toBe(AutomationActionStatus::Completed)
        ->and($note->status)->toBe(AutomationActionStatus::Completed)
        ->and($context->application()->fresh()->status)->toBe(ApplicationStatus::OnHold);
});
