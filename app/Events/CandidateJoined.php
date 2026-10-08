<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 8.2: a joining record was marked Joined (CandidateJoiningService::markJoined) — the
 * Outcome Loop's completed-hire anchor. Carries ids only, never models or personal data.
 */
class CandidateJoined implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $candidateId,
        public readonly int $applicationId,
        public readonly ?int $requisitionId,
        public readonly int $joiningId,
        public readonly string $joinedAt,
    ) {}
}
