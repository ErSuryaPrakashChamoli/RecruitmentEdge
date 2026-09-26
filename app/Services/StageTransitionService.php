<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\StageRequirement;
use App\Events\CandidateStageChanged;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\RecruitmentRejectionReason;
use App\Models\RequisitionPipelineStage;
use App\Services\Lifecycle\LifecycleGuard;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only code path allowed to change a CandidateApplication's stage or status. Every change is
 * written atomically with a permanent `candidate_stage_histories` row (Section 13: the historical
 * recruitment journey is never overwritten) and announced with CandidateStageChanged once the
 * transaction commits.
 *
 * Two ways to move forward:
 * - transitionTo() takes a canonical CandidateStage — used by the domain services (interviews,
 *   offers, joining) and legacy callers. On a requisition with a configured pipeline the
 *   application's pipeline stage follows along to the matching configured stage.
 * - moveToStage() takes a configured RequisitionPipelineStage and enforces that pipeline's rules
 *   (allowed transitions, non-skippable stages, terminal stages, required remarks/actions). An
 *   authorised override (pipeline.override + remarks) may bypass those rules — never the
 *   forward-only milestone order — and is flagged on the history row and in the audit log.
 */
class StageTransitionService
{
    public function __construct(
        private readonly PipelineTemplateService $pipelines,
        private readonly StageConfigurationService $stageConfiguration,
    ) {}

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

        $pipelineStage = $this->pipelineStageFor($application, $stage);

        return $this->writeMove($application, $stage, $pipelineStage, $actor, $remarks, false);
    }

    /**
     * Phase 8.3: a user-, Copilot- or automation-initiated move to a canonical stage. On a
     * requisition with a configured pipeline the move goes through moveToStage(), so the
     * pipeline's rules (allowed transitions, non-skippable and terminal stages, required remarks)
     * apply exactly as on the configured board; a legacy application without a pipeline keeps the
     * canonical forward-only rule. transitionTo() stays reserved for domain services recording a
     * fact that already happened (an interview scheduled, an offer released, a joining).
     */
    public function advance(CandidateApplication $application, CandidateStage $stage, ?Employee $actor = null, ?string $remarks = null): CandidateApplication
    {
        if ($application->current_stage === $stage) {
            throw new DomainException("The application is already at {$stage->label()}.");
        }

        if ($this->currentPipeline($application)->isEmpty()) {
            return $this->transitionTo($application, $stage, $actor, $remarks);
        }

        $target = $this->pipelineStageFor($application, $stage);

        if ($target === null) {
            throw new DomainException("This requisition's pipeline has no stage for {$stage->label()}.");
        }

        return $this->moveToStage($application, $target, $actor, $remarks);
    }

    /**
     * Move an application to a configured stage of its requisition's pipeline, enforcing that
     * pipeline's transition rules unless $override is set by an authorised actor.
     */
    public function moveToStage(CandidateApplication $application, RequisitionPipelineStage $target, ?Employee $actor = null, ?string $remarks = null, bool $override = false): CandidateApplication
    {
        if ($application->status !== ApplicationStatus::Active) {
            throw new DomainException("Cannot change the stage of an application that is not active (current status: {$application->status->label()}).");
        }

        if ($target->requisition_id !== $application->requisition_id || $target->superseded_at !== null) {
            throw new DomainException("\"{$target->name}\" is not a stage of this application's current pipeline.");
        }

        if ($target->milestone->order() < $application->current_stage->order()) {
            throw new DomainException("Cannot move an application backward from {$application->current_stage->label()} to {$target->name}.");
        }

        $current = $application->pipelineStage;

        if ($current !== null && $current->is($target)) {
            throw new DomainException("The application is already at \"{$target->name}\".");
        }

        if ($override) {
            if (! $this->canOverride($actor)) {
                throw new DomainException('You are not allowed to override pipeline transition rules.');
            }

            if (blank($remarks)) {
                throw new DomainException('A reason is required to override pipeline transition rules.');
            }
        } else {
            $this->guardConfiguredMove($application, $current, $target, $remarks);
        }

        return $this->writeMove($application, $target->milestone, $target, $actor, $remarks, $override);
    }

    /**
     * The configured stages an application may move to next without an override, in order. For a
     * legacy application with no pipeline stage this is every current-pipeline stage at or beyond
     * its milestone.
     *
     * @return Collection<int, RequisitionPipelineStage>
     */
    public function allowedNextStages(CandidateApplication $application): Collection
    {
        $pipeline = $this->currentPipeline($application);
        $current = $application->pipelineStage;
        $milestoneOrder = $application->current_stage->order();

        $forward = $pipeline->filter(fn (RequisitionPipelineStage $stage) => $stage->milestone->order() >= $milestoneOrder
            && ($current === null || $stage->sort_order > $current->sort_order));

        if ($current === null) {
            return $forward->values();
        }

        if ($current->is_terminal) {
            return collect();
        }

        $explicit = $current->allowedNextCodes();

        if ($explicit !== null) {
            return $forward->filter(fn (RequisitionPipelineStage $stage) => in_array($stage->code, $explicit, true))->values();
        }

        // Default forward rule: any later stage up to and including the first non-skippable one.
        $allowed = collect();

        foreach ($forward as $stage) {
            $allowed->push($stage);

            if (! $stage->is_skippable) {
                break;
            }
        }

        return $allowed;
    }

    /**
     * Every later stage an override may reach (forward-only on the canonical milestone order).
     *
     * @return Collection<int, RequisitionPipelineStage>
     */
    public function overridableStages(CandidateApplication $application): Collection
    {
        $current = $application->pipelineStage;

        return $this->currentPipeline($application)
            ->filter(fn (RequisitionPipelineStage $stage) => $stage->milestone->order() >= $application->current_stage->order()
                && ($current === null || ! $stage->is($current)))
            ->values();
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

        return $this->writeStatus($application, ApplicationStatus::OnHold, [], $actor, ApplicationStatus::OnHold->label().': '.$remarks);
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

        $attributes = match ($previousStatus) {
            ApplicationStatus::Rejected => ['rejection_reason_id' => null],
            ApplicationStatus::Dropout => ['dropout_reason_id' => null],
            default => [],
        };

        return $this->writeStatus($application, ApplicationStatus::Active, $attributes, $actor, filled($remarks) ? $remarks : "Reactivated from {$previousStatus->label()}");
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

        $pipelineStage = $application->pipelineStage;

        if ($pipelineStage !== null) {
            $allowed = $status === ApplicationStatus::Rejected ? $pipelineStage->allows_rejection : $pipelineStage->allows_dropout;

            if (! $allowed) {
                throw new DomainException("The stage \"{$pipelineStage->name}\" does not allow marking an application as {$status->label()}.");
            }
        }

        return $this->writeStatus($application, $status, [$reasonColumn => $reason->id], $actor, $remarks ?? $status->label().': '.$reason->name);
    }

    private function guardConfiguredMove(CandidateApplication $application, ?RequisitionPipelineStage $current, RequisitionPipelineStage $target, ?string $remarks): void
    {
        if ($current?->is_terminal) {
            throw new DomainException("\"{$current->name}\" is a terminal stage; the application cannot move on from it.");
        }

        if (! $this->allowedNextStages($application)->contains(fn (RequisitionPipelineStage $stage) => $stage->is($target))) {
            $from = $current?->name ?? $application->current_stage->label();

            throw new DomainException("Moving from \"{$from}\" to \"{$target->name}\" is not an allowed transition in this pipeline.");
        }

        if (blank($remarks) && ($target->requires(StageRequirement::Remarks) || ($current?->transitionRequiresRemarks($target) ?? false))) {
            throw new DomainException("Remarks are required to move to \"{$target->name}\".");
        }

        $unmet = $this->stageConfiguration->unmetRequirements($application, $target, $remarks);

        if ($unmet !== []) {
            $labels = implode(', ', array_map(fn (StageRequirement $r) => $r->label(), $unmet));

            throw new DomainException("\"{$target->name}\" requires: {$labels}.");
        }
    }

    private function writeMove(
        CandidateApplication $application,
        CandidateStage $stage,
        ?RequisitionPipelineStage $pipelineStage,
        ?Employee $actor,
        ?string $remarks,
        bool $override,
    ): CandidateApplication {
        $previousStage = $application->current_stage;
        $previousPipelineStageId = $application->pipeline_stage_id;
        $newPipelineStageId = $pipelineStage?->id ?? $previousPipelineStageId;

        DB::transaction(fn () => LifecycleGuard::allow(function () use ($application, $stage, $previousStage, $previousPipelineStageId, $newPipelineStageId, $actor, $remarks, $override): void {
            $application->forceFill([
                'current_stage' => $stage,
                'pipeline_stage_id' => $newPipelineStageId,
                'last_activity_at' => now(),
            ])->save();

            $application->stageHistory()->create([
                'previous_stage' => $previousStage,
                'new_stage' => $stage,
                'previous_pipeline_stage_id' => $previousPipelineStageId,
                'new_pipeline_stage_id' => $newPipelineStageId,
                'changed_by' => $actor?->id,
                'remarks' => $remarks,
                'is_override' => $override,
            ]);

            if ($override) {
                AuditLog::record(
                    $application,
                    'stage_override',
                    ['current_stage' => $previousStage->value, 'pipeline_stage_id' => $previousPipelineStageId],
                    ['current_stage' => $stage->value, 'pipeline_stage_id' => $newPipelineStageId, 'reason' => $remarks],
                );
            }
        }));

        $application->unsetRelation('pipelineStage');

        CandidateStageChanged::dispatch(
            $application, $previousStage, $stage, $application->status, $application->status,
            $previousPipelineStageId, $newPipelineStageId, $actor, $remarks, $override,
        );

        return $application;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function writeStatus(CandidateApplication $application, ApplicationStatus $status, array $attributes, ?Employee $actor, string $remarks): CandidateApplication
    {
        $previousStatus = $application->status;

        DB::transaction(fn () => LifecycleGuard::allow(function () use ($application, $status, $attributes, $actor, $remarks): void {
            $application->forceFill([
                ...$attributes,
                'status' => $status,
                'last_activity_at' => now(),
            ])->save();

            $application->stageHistory()->create([
                'previous_stage' => $application->current_stage,
                'new_stage' => $application->current_stage,
                'previous_pipeline_stage_id' => $application->pipeline_stage_id,
                'new_pipeline_stage_id' => $application->pipeline_stage_id,
                'changed_by' => $actor?->id,
                'remarks' => $remarks,
            ]);
        }));

        CandidateStageChanged::dispatch(
            $application, $application->current_stage, $application->current_stage, $previousStatus, $status,
            $application->pipeline_stage_id, $application->pipeline_stage_id, $actor, $remarks,
        );

        return $application;
    }

    /**
     * The configured stage matching a canonical milestone move, or null when the requisition has
     * no configured pipeline (legacy behaviour: only current_stage changes).
     */
    private function pipelineStageFor(CandidateApplication $application, CandidateStage $stage): ?RequisitionPipelineStage
    {
        $pipeline = $this->currentPipeline($application);

        if ($pipeline->isEmpty()) {
            return null;
        }

        $current = $application->pipelineStage;

        if ($current !== null && $current->milestone === $stage) {
            return $current;
        }

        return $this->pipelines->stageForMilestone($pipeline, $stage, $current?->superseded_at === null ? $current : null);
    }

    /**
     * @return Collection<int, RequisitionPipelineStage>
     */
    private function currentPipeline(CandidateApplication $application): Collection
    {
        return RequisitionPipelineStage::query()
            ->where('requisition_id', $application->requisition_id)
            ->current()
            ->get();
    }

    private function canOverride(?Employee $actor): bool
    {
        return (bool) $actor?->user?->can('pipeline.override');
    }
}
