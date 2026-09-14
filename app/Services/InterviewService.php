<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\InterviewMode;
use App\Enums\InterviewResult;
use App\Enums\InterviewStatus;
use App\Filament\Resources\Interviews\InterviewResource;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\RecruitmentRejectionReason;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only code path allowed to schedule, reschedule, hold, confirm or complete an interview.
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
     * @param  array{interviewer_id: int|string, scheduled_at: CarbonInterface|string, mode: InterviewMode|string, round_number?: int|string|null, round_name?: string|null, location?: string|null, meeting_link?: string|null, remarks?: string|null}  $data
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
                'remarks' => $data['remarks'] ?? null,
                'status' => InterviewStatus::Scheduled,
                'created_by' => $actor?->id,
            ]);

            if ($application->current_stage->order() < CandidateStage::InterviewScheduled->order()) {
                $this->stageTransitions->transitionTo($application, CandidateStage::InterviewScheduled, $actor, "Interview round {$roundNumber} scheduled");
            }

            return $interview;
        });

        $this->notifyParticipants(
            $interview,
            'Interview scheduled',
            "Interview round {$interview->round_number} with {$application->candidate->full_name} is scheduled for {$interview->scheduled_at->format('d M Y, h:i A')}.",
            'info',
        );

        return $interview;
    }

    public function reschedule(Interview $interview, CarbonInterface $scheduledAt, ?string $remarks = null, ?Employee $actor = null): Interview
    {
        $this->ensureNotTerminal($interview, 'reschedule');

        $interview->forceFill([
            'scheduled_at' => $scheduledAt,
            'status' => InterviewStatus::Rescheduled,
            'remarks' => $this->appendRemarks($interview, 'Rescheduled', $remarks, $actor),
        ])->save();

        $this->notifyParticipants(
            $interview,
            'Interview rescheduled',
            "The interview for {$interview->candidateApplication->candidate->full_name} has been rescheduled to {$interview->scheduled_at->format('d M Y, h:i A')}.",
            'warning',
        );

        return $interview;
    }

    public function hold(Interview $interview, string $remarks, ?Employee $actor = null): Interview
    {
        $this->ensureNotTerminal($interview, 'put on hold');

        if (blank($remarks)) {
            throw new DomainException('Remarks are required to put an interview on hold.');
        }

        $interview->forceFill([
            'status' => InterviewStatus::Hold,
            'remarks' => $this->appendRemarks($interview, 'On hold', $remarks, $actor),
        ])->save();

        return $interview;
    }

    public function confirm(Interview $interview): Interview
    {
        if (! $interview->status->awaitsConfirmation()) {
            throw new DomainException("Only a scheduled or rescheduled interview can be confirmed (current status: {$interview->status->label()}).");
        }

        $interview->forceFill(['status' => InterviewStatus::Confirmed])->save();

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
            $interview->forceFill([
                'status' => InterviewStatus::Completed,
                'result' => $result,
                'rejection_reason_id' => $result === InterviewResult::Rejected ? $rejectionReason->id : null,
            ])->save();

            $application = $interview->candidateApplication;

            if ($result === InterviewResult::Rejected) {
                $this->stageTransitions->reject($application, $rejectionReason, $actor, 'Rejected at interview round '.$interview->round_number);
            } else {
                $this->stageTransitions->transitionTo($application, $this->stageForRound($interview->round_number), $actor);
            }

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
