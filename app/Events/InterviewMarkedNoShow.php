<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 8.3: the candidate did not attend an interview (InterviewService::markNoShow). Ids only,
 * after commit; an automation trigger (interview.no_show).
 */
class InterviewMarkedNoShow implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $interviewId,
        public readonly int $applicationId,
        public readonly ?int $actorEmployeeId,
    ) {}
}
