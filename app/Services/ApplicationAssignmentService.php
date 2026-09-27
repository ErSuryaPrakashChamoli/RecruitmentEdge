<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\EmployeeStatus;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Enums\RequisitionStatus;
use App\Enums\StageHistoryEvent;
use App\Events\ApplicationMovedToRequisition;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\RecruitmentRequisition;
use App\Models\RequisitionPipelineStage;
use App\Models\User;
use App\Services\Lifecycle\LifecycleGuard;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8.3: the only code path allowed to change which requisition, candidate or recruiter an
 * application belongs to. An ordinary application edit can no longer change these (the model
 * guards them); moving to another requisition and reassigning the recruiter are explicit,
 * authorised, reasoned and audited operations. The candidate of an application never changes —
 * a different candidate is a different application.
 */
class ApplicationAssignmentService
{
    public function __construct(
        private readonly PipelineTemplateService $pipelines,
        private readonly HierarchyService $hierarchy,
    ) {}

    /**
     * Moves an active application with nothing open (no pending interview, offer or joining) to
     * another Open requisition the actor can see. The canonical stage is kept; the configured
     * stage is re-mapped onto the destination's pipeline. The move is written to the stage history,
     * audited with both requisitions and the reason, and announced after commit.
     */
    public function moveToRequisition(CandidateApplication $application, RecruitmentRequisition $destination, User $actor, string $reason): CandidateApplication
    {
        $reason = trim($reason);

        if (! $actor->can('move', $application)) {
            throw new DomainException('You are not allowed to move this application.');
        }

        if ($reason === '') {
            throw new DomainException('A reason is required to move an application to another requisition.');
        }

        if ($application->status !== ApplicationStatus::Active) {
            throw new DomainException("Only an active application can be moved (current status: {$application->status->label()}).");
        }

        if ($destination->is($application->requisition)) {
            throw new DomainException('The application is already on this requisition.');
        }

        if ($destination->status !== RequisitionStatus::Open || ! RecruitmentRequisition::query()->visibleTo($actor)->whereKey($destination->id)->exists()) {
            throw new DomainException('Choose an Open requisition you can see.');
        }

        if (CandidateApplication::withTrashed()->where('candidate_id', $application->candidate_id)->where('requisition_id', $destination->id)->exists()) {
            throw new DomainException('This candidate already has an application on that requisition.');
        }

        $this->ensureNothingOpen($application);

        $from = $application->requisition;

        return DB::transaction(fn (): CandidateApplication => LifecycleGuard::allow(function () use ($application, $destination, $actor, $reason, $from): CandidateApplication {
            $pipeline = RequisitionPipelineStage::query()->where('requisition_id', $destination->id)->current()->get();
            $pipelineStage = $pipeline->isEmpty() ? null : $this->pipelines->stageForMilestone($pipeline, $application->current_stage);
            $previousPipelineStageId = $application->pipeline_stage_id;

            $application->forceFill([
                'requisition_id' => $destination->id,
                'pipeline_stage_id' => $pipelineStage?->id,
                'last_activity_at' => now(),
            ])->save();

            $application->stageHistory()->create([
                'previous_stage' => $application->current_stage,
                'new_stage' => $application->current_stage,
                'event' => StageHistoryEvent::MovedRequisition,
                'previous_pipeline_stage_id' => $previousPipelineStageId,
                'new_pipeline_stage_id' => $pipelineStage?->id,
                'changed_by' => $actor->employee_id,
                'remarks' => "Moved from {$from->code} to {$destination->code}: {$reason}",
            ]);

            AuditLog::record($application, 'application_moved', ['requisition_id' => $from->id, 'pipeline_stage_id' => $previousPipelineStageId], [
                'requisition_id' => $destination->id, 'pipeline_stage_id' => $pipelineStage?->id, 'reason' => mb_substr($reason, 0, 255), 'by_user_id' => $actor->id,
            ]);

            ApplicationMovedToRequisition::dispatch($application->id, $from->id, $destination->id, $actor->id);

            return $application->unsetRelation('requisition')->unsetRelation('pipelineStage');
        }));
    }

    /**
     * Reassigns the recruiter who owns an application. The actor needs the reassign ability on the
     * application and must be able to see the new recruiter in their hierarchy.
     */
    public function reassignRecruiter(CandidateApplication $application, Employee $recruiter, User $actor, ?string $reason = null): CandidateApplication
    {
        if (! $actor->can('reassign', $application)) {
            throw new DomainException('You are not allowed to reassign this application.');
        }

        if (! $this->hierarchy->canView($actor, $recruiter)) {
            throw new DomainException('Choose a recruiter in your own team.');
        }

        // Phase 8.4: current responsibility only ever moves to someone who is currently employed.
        if ($recruiter->trashed() || $recruiter->status !== EmployeeStatus::Active) {
            throw new DomainException('Choose a recruiter who is currently active.');
        }

        if ((int) $application->recruiter_id === $recruiter->id) {
            return $application;
        }

        return DB::transaction(fn (): CandidateApplication => LifecycleGuard::allow(function () use ($application, $recruiter, $actor, $reason): CandidateApplication {
            $previous = $application->recruiter_id;

            $application->forceFill(['recruiter_id' => $recruiter->id, 'last_activity_at' => now()])->save();

            AuditLog::record($application, 'application_reassigned', ['recruiter_id' => $previous], [
                'recruiter_id' => $recruiter->id, 'reason' => filled($reason) ? mb_substr(trim($reason), 0, 255) : null, 'by_user_id' => $actor->id,
            ]);

            return $application->unsetRelation('recruiter');
        }));
    }

    private function ensureNothingOpen(CandidateApplication $application): void
    {
        $openInterview = $application->interviews()->whereNotIn('status', [InterviewStatus::Completed->value, InterviewStatus::Cancelled->value, InterviewStatus::NoShow->value])->exists();
        $openOffer = $application->offers()->whereIn('status', [OfferStatus::Draft->value, OfferStatus::Initiated->value, OfferStatus::Released->value, OfferStatus::Accepted->value])->exists();
        $openJoining = $application->joining()->whereIn('status', [JoiningStatus::Expected->value, JoiningStatus::Confirmed->value, JoiningStatus::Joined->value])->exists();

        if ($openInterview || $openOffer || $openJoining) {
            throw new DomainException('Resolve this application\'s open interviews, offers and joining before moving it to another requisition.');
        }
    }
}
