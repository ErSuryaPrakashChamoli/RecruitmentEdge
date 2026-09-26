<?php

namespace App\Events;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Models\CandidateApplication;
use App\Models\Employee;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by StageTransitionService after every stage or status change it writes (forward moves,
 * rejection, dropout, hold, reactivation) — only once the surrounding transaction commits, so a
 * listener never reacts to a change that was rolled back. The authoritative record remains the
 * candidate_stage_histories row; this event is the integration point for referral sync, future
 * workflow automation (Phase 6) and AI signals (Phase 7).
 */
class CandidateStageChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly CandidateApplication $application,
        public readonly ?CandidateStage $previousStage,
        public readonly CandidateStage $newStage,
        public readonly ApplicationStatus $previousStatus,
        public readonly ApplicationStatus $newStatus,
        public readonly ?int $previousPipelineStageId = null,
        public readonly ?int $newPipelineStageId = null,
        public readonly ?Employee $actor = null,
        public readonly ?string $remarks = null,
        public readonly bool $isOverride = false,
    ) {}

    public function statusChanged(): bool
    {
        return $this->previousStatus !== $this->newStatus;
    }
}
