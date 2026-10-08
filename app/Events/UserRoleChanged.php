<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 8.4: a staff login's roles changed (RoleAssignmentService). Ids only — pending AI actions
 * the user proposed are re-evaluated against the new authority before they can run.
 */
class UserRoleChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly ?int $actorId,
    ) {}
}
