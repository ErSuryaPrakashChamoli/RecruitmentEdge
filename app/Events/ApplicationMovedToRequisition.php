<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 8.3: an application was explicitly moved to another requisition
 * (ApplicationAssignmentService::moveToRequisition). Ids only, after commit.
 */
class ApplicationMovedToRequisition implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $applicationId,
        public readonly int $fromRequisitionId,
        public readonly int $toRequisitionId,
        public readonly ?int $actorUserId,
    ) {}
}
