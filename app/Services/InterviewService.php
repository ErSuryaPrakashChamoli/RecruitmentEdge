<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\InterviewMode;
use App\Enums\InterviewResult;
use App\Enums\InterviewStatus;
use App\Events\InterviewCancelled;
use App\Events\InterviewCompleted;
use App\Events\InterviewConfirmed;
use App\Events\InterviewMarkedNoShow;
use App\Events\InterviewRescheduled;
use App\Events\InterviewScheduled;
use App\Filament\Resources\Interviews\InterviewResource;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\Interviewer;
use App\Models\RecruitmentRejectionReason;
use App\Services\Lifecycle\LifecycleGuard;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only code path allowed to schedule, reschedule, hold, confirm, cancel, mark a no-show or
 * complete an interview (Phase 8.3: the model refuses any other write).
 * Section 15: "Interview feedback must be mandatory before completing the interview" — enforced
 * here, not just as a UI validation, so it can't be bypassed by any other write path. Scheduling
 * also keeps the application's pipeline stage in sync (forward-only) inside the same transaction.
 */
class InterviewService
{
    public function __construct(
        private readonly StageTransitionService $stageTransitions,
        private readonly NotificationDispatchService $notifications,
    ) {}

    /**
     * Schedules a new interview round for an active application, advancing the application to
     * Interview Scheduled when it hasn't reached that stage yet (an application already past it —
     * e.g. scheduling round 2 after Interview 1 — keeps its stage), then notifies the interviewer
     * and the application's recruiter.
     *
     * @param  array{interviewer_id: int|string, scheduled_at: CarbonInterface|string, mode: InterviewMode|string, round_number?: int|string|null, round_name?: string|null, location?: string|null, meeting_link?: string|null, meeting_provider?: string|null, remarks?: string|null}  $data
     */
    public function schedule(CandidateApplication $application, array $data, ?Employee $actor = null): Interview
    {
        if ($application->status !== ApplicationStatus::Active) {
            throw new DomainException("Cannot schedule an interview for an application that is not active (current status: {$application->status->label()}).");
        }

        $mode = $data['mode'] instanceof InterviewMode ? $data['mode'] : InterviewMode::tryFrom((string) ($data['mode'] ?? ''));

        if ($mode === null) {
            throw new DomainException('A valid interview mode is required.');
        }

        if (blank($data['interviewer_id'] ?? null) || blank($data['scheduled_at'] ?? null)) {
            throw new DomainException('An interviewer and a scheduled date/time are required to schedule an interview.');
        }

        // Phase 8.6 (D8.6-009): a new interview goes to someone on the active interviewer list.
        if (! Interviewer::isActiveInterviewer((int) $data['interviewer_id'])) {
            throw new DomainException('The interviewer must be on the active interviewer list (Administration → Interviewers).');
        }

        $interview = DB::transaction(function () use ($application, $data, $mode, $actor): Interview {
            $roundNumber = filled($data['round_number'] ?? null)
                ? (int) $data['round_number']
                : $application->interviews()->count() + 1;

            $interview = $application->interviews()->create([
                'round_number' => $roundNumber,
                'round_name' => $data['round_name'] ?? null,
                'interviewer_id' => $data['interviewer_id'],
                'scheduled_at' => Carbon::parse($data['scheduled_at']),
                'mode' => $mode,
                'location' => $data['location'] ?? null,
                'meeting_link' => $data['meeting_link'] ?? null,
                'meeting_provider' => filled($data['meeting_provider'] ?? null) ? $data['meeting_provider'] : null,
                'remarks' => $data['remarks'] ?? null,
                'status' => InterviewStatus::Scheduled,
                'created_by' => $actor?->id,
            ]);

            if ($application->current_stage->order() < CandidateStage::InterviewScheduled->order()) {
                $this->stageTransitions->transitionTo($application, CandidateStage::InterviewScheduled, $actor, "Interview round {$roundNumber} scheduled");
            }

            return $interview;
        });

        InterviewScheduled::dispatch($interview, $actor);

        $this->notifyParticipants(
            $interview,
            'Interview scheduled',
            "Interview round {$interview->round_number} with {$application->candidate->full_name} is scheduled for {$interview->scheduled_at->format('d M Y, h:i A')}.",
            'info',
        );

        return $interview;
    }

    /**
     * @param  array{interviewer_id?: int|null, mode?: InterviewMode|null, location?: string|null, meeting_link?: string|null}  $changes  optional slot details that move with the new time (e.g. a self-scheduled slot with another interviewer)
     */
    public function reschedule(Interview $interview, CarbonInterface $scheduledAt, ?string $remarks = null, ?Employee $actor = null, array $changes = []): Interview
    {
        $this->ensureNotTerminal($interview, 'reschedule');

        LifecycleGuard::allow(fn () => $interview->forceFill([
            ...array_filter(array_intersect_key($changes, array_flip(['interviewer_id', 'mode', 'location', 'meeting_link'])), fn ($value) => $value !== null),
            'scheduled_at' => $scheduledAt,
            'status' => InterviewStatus::Rescheduled,
            'remarks' => $this->appendRemarks($interview, 'Rescheduled', $remarks, $actor),
        ])->save());

        InterviewRescheduled::dispatch($interview, $actor);

        $this->notifyParticipants(
            $interview,
            'Interview rescheduled',
            "The interview for {$interview->candidateApplication->candidate->full_name} has been rescheduled to {$interview->scheduled_at->format('d M Y, h:i A')}.",
            'warning',
        );

        return $interview;
    }

    /**
     * Cancels a not-yet-finished interview (e.g. the candidate cancelled a self-scheduled slot).
     * The application's stage is left as-is — cancelling a meeting is not a pipeline decision.
     * $cause is set when the cancellation is a consequence of the application closing (Phase 8.3
     * cascade); listeners use it so the candidate isn't sent a separate cancellation message.
     */
    public function cancel(Interview $interview, string $remarks, ?Employee $actor = null, ?string $cause = null): Interview
    {
        $this->ensureNotTerminal($interview, 'cancel');

        if (blank($remarks)) {
            throw new DomainException('Remarks are required to cancel an interview.');
        }

        LifecycleGuard::allow(fn () => $interview->forceFill([
            'status' => InterviewStatus::Cancelled,
            'remarks' => $this->appendRemarks($interview, 'Cancelled', $remarks, $actor),
        ])->save());

        InterviewCancelled::dispatch($interview, $actor, $cause);

        $this->notifyParticipants(
            $interview,
            'Interview cancelled',
            "The interview for {$interview->candidateApplication->candidate->full_name} on {$interview->scheduled_at->format('d M Y, h:i A')} was cancelled.",
            'danger',
        );

        return $interview;
    }

    public function hold(Interview $interview, string $remarks, ?Employee $actor = null): Interview
    {
        $this->ensureNotTerminal($interview, 'put on hold');

        if (blank($remarks)) {
            throw new DomainException('Remarks are required to put an interview on hold.');
        }

        LifecycleGuard::allow(fn () => $interview->forceFill([
            'status' => InterviewStatus::Hold,
            'remarks' => $this->appendRemarks($interview, 'On hold', $remarks, $actor),
        ])->save());

        return $interview;
    }

    /**
     * Phase 8.3: the candidate did not attend. Recorded once (a finished interview cannot become a
     * no-show), announced after commit and the recruiter is alerted. The application's stage is
     * left as-is — a no-show is a fact about the meeting, not a hiring decision.
     */
    public function markNoShow(Interview $interview, ?Employee $actor = null, ?string $remarks = null): Interview
    {
        $this->ensureNotTerminal($interview, 'mark as a no-show');

        LifecycleGuard::allow(fn () => $interview->forceFill([
            'status' => InterviewStatus::NoShow,
            'remarks' => $this->appendRemarks($interview, 'No-show', $remarks ?? 'Candidate did not attend', $actor),
        ])->save());

        InterviewMarkedNoShow::dispatch($interview->id, $interview->candidate_application_id, $actor?->id);

        $application = $interview->candidateApplication;

        $this->notifications->alert(
            $application->recruiter?->user,
            'Interviews',
            'Candidate no-show',
            "{$application->candidate->full_name} did not show up for their interview.",
            'danger',
            InterviewResource::getUrl('edit', ['record' => $interview]),
        );

        return $interview;
    }

    public function confirm(Interview $interview): Interview
    {
        if (! $interview->status->awaitsConfirmation()) {
            throw new DomainException("Only a scheduled or rescheduled interview can be confirmed (current status: {$interview->status->label()}).");
        }

        LifecycleGuard::allow(fn () => $interview->forceFill(['status' => InterviewStatus::Confirmed])->save());

        InterviewConfirmed::dispatch($interview);

        return $interview;
    }

    public function complete(
        Interview $interview,
        InterviewResult $result,
        ?Employee $actor = null,
        ?RecruitmentRejectionReason $rejectionReason = null,
    ): Interview {
        if ($interview->status->isTerminal()) {
            throw new DomainException("Cannot complete an interview that is already {$interview->status->label()}.");
        }

        if ($interview->feedback()->doesntExist()) {
            throw new DomainException('At least one interview feedback entry is required before completing an interview.');
        }

        if ($result === InterviewResult::Rejected && $rejectionReason === null) {
            throw new DomainException('A rejection reason is required to complete an interview as Rejected.');
        }

        return DB::transaction(function () use ($interview, $result, $actor, $rejectionReason): Interview {
            LifecycleGuard::allow(fn () => $interview->forceFill([
                'status' => InterviewStatus::Completed,
                'result' => $result,
                'rejection_reason_id' => $result === InterviewResult::Rejected ? $rejectionReason->id : null,
            ])->save());

            // Phase 8.3: the round's decision locks its feedback; later changes are corrections.
            app(InterviewFeedbackService::class)->lock($interview);

            $application = $interview->candidateApplication;

            if ($result === InterviewResult::Rejected) {
                $this->stageTransitions->reject($application, $rejectionReason, $actor, 'Rejected at interview round '.$interview->round_number);
            } else {
                $this->stageTransitions->transitionTo($application, $this->stageForRound($interview->round_number), $actor);
            }

            InterviewCompleted::dispatch($interview, $actor);

            return $interview;
        });
    }

    /**
     * Marks an application Selected outright — a distinct, explicit decision from any single
     * round's result, since an org may run further rounds even after a positive round outcome.
     */
    public function selectCandidate(Interview $interview, ?Employee $actor = null): void
    {
        if (! $this->canSelectCandidateFrom($interview)) {
            throw new DomainException('A candidate can only be selected from a completed interview that was not rejected.');
        }

        $this->stageTransitions->transitionTo($interview->candidateApplication, CandidateStage::Selected, $actor, 'Selected after interview round '.$interview->round_number);
    }

    public function canSelectCandidateFrom(Interview $interview): bool
    {
        return $interview->status === InterviewStatus::Completed
            && $interview->result !== InterviewResult::Rejected;
    }

    private function ensureNotTerminal(Interview $interview, string $verb): void
    {
        if ($interview->status->isTerminal()) {
            throw new DomainException("Cannot {$verb} an interview that is already {$interview->status->label()}.");
        }
    }

    private function appendRemarks(Interview $interview, string $prefix, ?string $remarks, ?Employee $actor): ?string
    {
        if (blank($remarks)) {
            return $interview->remarks;
        }

        $line = "{$prefix}: {$remarks}".($actor !== null ? " ({$actor->fullName()})" : '');

        return filled($interview->remarks) ? $interview->remarks."\n".$line : $line;
    }

    /**
     * Interviewer and recruiter are often the same person (a recruiter interviewing their own
     * candidate) — de-duplicated so nobody gets the same alert twice.
     */
    private function notifyParticipants(Interview $interview, string $title, string $body, string $color): void
    {
        $url = InterviewResource::getUrl('edit', ['record' => $interview]);

        collect([
            $interview->interviewer?->user,
            $interview->candidateApplication->recruiter?->user,
        ])
            ->filter()
            ->unique('id')
            ->each(fn ($recipient) => $this->notifications->alert($recipient, 'Interviews', $title, $body, $color, $url));
    }

    private function stageForRound(int $roundNumber): CandidateStage
    {
        return match (true) {
            $roundNumber <= 1 => CandidateStage::Interview1,
            $roundNumber === 2 => CandidateStage::Interview2,
            default => CandidateStage::FinalInterview,
        };
    }
}
