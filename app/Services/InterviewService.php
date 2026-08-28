<?php

namespace App\Services;

use App\Enums\CandidateStage;
use App\Enums\InterviewResult;
use App\Enums\InterviewStatus;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\RecruitmentRejectionReason;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The only code path allowed to complete an interview. Section 15: "Interview feedback must be
 * mandatory before completing the interview" — enforced here, not just as a UI validation, so it
 * can't be bypassed by any other write path.
 */
class InterviewService
{
    public function __construct(private readonly StageTransitionService $stageTransitions) {}

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
        $this->stageTransitions->transitionTo($interview->candidateApplication, CandidateStage::Selected, $actor, 'Selected after interview round '.$interview->round_number);
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
