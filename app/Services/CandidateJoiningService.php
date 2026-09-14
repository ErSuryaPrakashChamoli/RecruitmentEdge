<?php

namespace App\Services;

use App\Enums\CandidateStage;
use App\Enums\DocumentStatus;
use App\Enums\JoiningStatus;
use App\Filament\Resources\CandidateJoinings\CandidateJoiningResource;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\RecruitmentRejectionReason;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a CandidateJoining's status in sync with its application's pipeline stage — a joining
 * confirmation/actual join is also a pipeline event, not just a Joining Tracker field.
 */
class CandidateJoiningService
{
    public function __construct(
        private readonly StageTransitionService $stageTransitions,
        private readonly RecruiterIncentiveCalculator $incentiveCalculator,
        private readonly NotificationDispatchService $notifications,
    ) {}

    public function confirm(CandidateJoining $joining, ?Employee $actor = null): CandidateJoining
    {
        $this->guardActive($joining);

        return DB::transaction(function () use ($joining, $actor): CandidateJoining {
            $joining->forceFill(['status' => JoiningStatus::Confirmed, 'confirmed_at' => now()])->save();

            $this->stageTransitions->transitionTo($joining->candidateApplication, CandidateStage::JoiningConfirmed, $actor);

            return $joining;
        });
    }

    public function markJoined(CandidateJoining $joining, ?Carbon $actualDoj = null, ?Employee $actor = null): CandidateJoining
    {
        $this->guardActive($joining);

        return DB::transaction(function () use ($joining, $actualDoj, $actor): CandidateJoining {
            $joining->forceFill(['status' => JoiningStatus::Joined, 'actual_doj' => $actualDoj ?? now()])->save();

            $this->stageTransitions->transitionTo($joining->candidateApplication, CandidateStage::Joined, $actor);

            // Section 25: joining incentives are calculated the moment a join is confirmed as a
            // fact, never merely on selection or offer acceptance.
            $this->incentiveCalculator->calculateForJoining($joining);

            return $joining;
        });
    }

    public function markNoShow(CandidateJoining $joining, RecruitmentRejectionReason $reason, ?Employee $actor = null): CandidateJoining
    {
        $this->guardActive($joining);

        return DB::transaction(function () use ($joining, $reason, $actor): CandidateJoining {
            $joining->forceFill(['status' => JoiningStatus::NoShow, 'dropout_reason_id' => $reason->id])->save();

            $this->stageTransitions->dropout($joining->candidateApplication, $reason, $actor, 'Did not join (no-show)');

            return $joining;
        });
    }

    public function markDropout(CandidateJoining $joining, RecruitmentRejectionReason $reason, ?Employee $actor = null): CandidateJoining
    {
        $this->guardActive($joining);

        return DB::transaction(function () use ($joining, $reason, $actor): CandidateJoining {
            $joining->forceFill(['status' => JoiningStatus::Dropout, 'dropout_reason_id' => $reason->id])->save();

            $this->stageTransitions->dropout($joining->candidateApplication, $reason, $actor, 'Dropped out before joining');

            $application = $joining->candidateApplication;
            $this->notifications->alert(
                $application->recruiter?->user,
                'Joining',
                'Candidate dropout',
                "{$application->candidate->full_name} dropped out before joining: {$reason->name}.",
                'danger',
                CandidateJoiningResource::getUrl('edit', ['record' => $joining]),
            );

            return $joining;
        });
    }

    /**
     * Post-join milestone: the joiner's documents are verified. Only valid once the candidate has
     * actually joined; sets `documents_status` and advances the application stage together.
     */
    public function markDocumentsCompleted(CandidateJoining $joining, ?Employee $actor = null): CandidateJoining
    {
        $this->guardPostJoinMilestone($joining, CandidateStage::DocumentsCompleted);

        return DB::transaction(function () use ($joining, $actor): CandidateJoining {
            $joining->forceFill(['documents_status' => DocumentStatus::Verified])->save();

            $this->stageTransitions->transitionTo($joining->candidateApplication, CandidateStage::DocumentsCompleted, $actor);

            return $joining;
        });
    }

    /**
     * Final post-join milestone. Requires documents to be completed first so the stage path stays
     * Joined -> Documents Completed -> Onboarding Completed.
     */
    public function markOnboardingCompleted(CandidateJoining $joining, ?Employee $actor = null): CandidateJoining
    {
        $this->guardPostJoinMilestone($joining, CandidateStage::OnboardingCompleted);

        if ($joining->candidateApplication->current_stage->order() < CandidateStage::DocumentsCompleted->order()) {
            throw new DomainException('Documents must be completed before onboarding can be marked complete.');
        }

        return DB::transaction(function () use ($joining, $actor): CandidateJoining {
            $this->stageTransitions->transitionTo($joining->candidateApplication, CandidateStage::OnboardingCompleted, $actor);

            return $joining;
        });
    }

    private function guardPostJoinMilestone(CandidateJoining $joining, CandidateStage $milestone): void
    {
        if ($joining->status !== JoiningStatus::Joined) {
            throw new DomainException("{$milestone->label()} can only be recorded once the candidate has joined.");
        }

        if ($joining->candidateApplication->current_stage->order() >= $milestone->order()) {
            throw new DomainException("{$milestone->label()} has already been recorded for this candidate.");
        }
    }

    private function guardActive(CandidateJoining $joining): void
    {
        if (in_array($joining->status, [JoiningStatus::Joined, JoiningStatus::NoShow, JoiningStatus::Dropout], true)) {
            throw new DomainException("This joining record is already {$joining->status->label()} and cannot be changed further.");
        }
    }
}
