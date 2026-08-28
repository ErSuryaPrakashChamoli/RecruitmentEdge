<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\RecruitmentRejectionReason;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The only code path allowed to change a CandidateApplication's stage or status. Every change is
 * written atomically with a permanent `candidate_stage_histories` row (Section 13: the historical
 * recruitment journey is never overwritten).
 */
class StageTransitionService
{
    /**
     * Move an application forward to a later pipeline stage. Backward moves are rejected — the
     * canonical stage order (CandidateStage::order()) exists precisely so funnel/conversion
     * analytics stay well-defined; correcting a mistaken stage is an explicit admin action, not a
     * silent regression through this method.
     */
    public function transitionTo(CandidateApplication $application, CandidateStage $stage, ?Employee $actor = null, ?string $remarks = null): CandidateApplication
    {
        if ($application->status !== ApplicationStatus::Active) {
            throw new DomainException("Cannot change the stage of an application that is not active (current status: {$application->status->label()}).");
        }

        if ($stage->order() < $application->current_stage->order()) {
            throw new DomainException("Cannot move an application backward from {$application->current_stage->label()} to {$stage->label()}.");
        }

        return DB::transaction(function () use ($application, $stage, $actor, $remarks): CandidateApplication {
            $previousStage = $application->current_stage;

            $application->forceFill([
                'current_stage' => $stage,
                'last_activity_at' => now(),
            ])->save();

            $application->stageHistory()->create([
                'previous_stage' => $previousStage,
                'new_stage' => $stage,
                'changed_by' => $actor?->id,
                'remarks' => $remarks,
            ]);

            return $application;
        });
    }

    /**
     * Reject an application out of the pipeline. The stage is left as-is (it records where the
     * rejection happened) — only `status` and `rejection_reason_id` change.
     */
    public function reject(CandidateApplication $application, RecruitmentRejectionReason $reason, ?Employee $actor = null, ?string $remarks = null): CandidateApplication
    {
        return $this->setTerminalStatus($application, ApplicationStatus::Rejected, 'rejection_reason_id', $reason, $actor, $remarks);
    }

    /**
     * Mark a candidate as a dropout (e.g. withdrew, did not join). Distinct from rejection: the
     * candidate walked away rather than being screened out.
     */
    public function dropout(CandidateApplication $application, RecruitmentRejectionReason $reason, ?Employee $actor = null, ?string $remarks = null): CandidateApplication
    {
        return $this->setTerminalStatus($application, ApplicationStatus::Dropout, 'dropout_reason_id', $reason, $actor, $remarks);
    }

    /**
     * Reactivate a previously rejected/dropped-out/held application, clearing whichever reason
     * corresponds to the status it is leaving.
     */
    public function reactivate(CandidateApplication $application, ?Employee $actor = null, ?string $remarks = null): CandidateApplication
    {
        return DB::transaction(function () use ($application, $actor, $remarks): CandidateApplication {
            $application->forceFill([
                'status' => ApplicationStatus::Active,
                'rejection_reason_id' => null,
                'dropout_reason_id' => null,
                'last_activity_at' => now(),
            ])->save();

            $application->stageHistory()->create([
                'previous_stage' => $application->current_stage,
                'new_stage' => $application->current_stage,
                'changed_by' => $actor?->id,
                'remarks' => $remarks ?? 'Reactivated',
            ]);

            return $application;
        });
    }

    private function setTerminalStatus(
        CandidateApplication $application,
        ApplicationStatus $status,
        string $reasonColumn,
        RecruitmentRejectionReason $reason,
        ?Employee $actor,
        ?string $remarks,
    ): CandidateApplication {
        if ($application->status !== ApplicationStatus::Active) {
            throw new DomainException("Application is already {$application->status->label()}.");
        }

        return DB::transaction(function () use ($application, $status, $reasonColumn, $reason, $actor, $remarks): CandidateApplication {
            $application->forceFill([
                'status' => $status,
                $reasonColumn => $reason->id,
                'last_activity_at' => now(),
            ])->save();

            $application->stageHistory()->create([
                'previous_stage' => $application->current_stage,
                'new_stage' => $application->current_stage,
                'changed_by' => $actor?->id,
                'remarks' => $remarks ?? $status->label().': '.$reason->name,
            ]);

            return $application;
        });
    }
}
