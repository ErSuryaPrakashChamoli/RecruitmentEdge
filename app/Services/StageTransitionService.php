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
     * Park an active application without rejecting it (e.g. requisition paused, candidate asked
     * to wait). Remarks are mandatory since there is no reason list for holds — they are the only
     * record of why the application was parked. Like reject/dropout, the stage is left as-is.
     */
    public function hold(CandidateApplication $application, ?Employee $actor, string $remarks): CandidateApplication
    {
        if ($application->status !== ApplicationStatus::Active) {
            throw new DomainException("Only an active application can be put on hold (current status: {$application->status->label()}).");
        }

        if (blank($remarks)) {
            throw new DomainException('Remarks are required to put an application on hold.');
        }

        return DB::transaction(function () use ($application, $actor, $remarks): CandidateApplication {
            $application->forceFill([
                'status' => ApplicationStatus::OnHold,
                'last_activity_at' => now(),
            ])->save();

            $application->stageHistory()->create([
                'previous_stage' => $application->current_stage,
                'new_stage' => $application->current_stage,
                'changed_by' => $actor?->id,
                'remarks' => ApplicationStatus::OnHold->label().': '.$remarks,
            ]);

            return $application;
        });
    }

    /**
     * Reactivate a previously rejected/dropped-out/held application, clearing whichever reason
     * corresponds to the status it is leaving (a hold has no reason to clear).
     */
    public function reactivate(CandidateApplication $application, ?Employee $actor = null, ?string $remarks = null): CandidateApplication
    {
        $previousStatus = $application->status;

        if ($previousStatus === ApplicationStatus::Active) {
            throw new DomainException('Application is already active.');
        }

        return DB::transaction(function () use ($application, $previousStatus, $actor, $remarks): CandidateApplication {
            $attributes = [
                'status' => ApplicationStatus::Active,
                'last_activity_at' => now(),
            ];

            match ($previousStatus) {
                ApplicationStatus::Rejected => $attributes['rejection_reason_id'] = null,
                ApplicationStatus::Dropout => $attributes['dropout_reason_id'] = null,
                default => null,
            };

            $application->forceFill($attributes)->save();

            $application->stageHistory()->create([
                'previous_stage' => $application->current_stage,
                'new_stage' => $application->current_stage,
                'changed_by' => $actor?->id,
                'remarks' => filled($remarks) ? $remarks : "Reactivated from {$previousStatus->label()}",
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

        if (! $reason->isSelectable()) {
            throw new DomainException("The reason \"{$reason->name}\" is no longer active and cannot be used.");
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
