<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 8.4: a staff login's access was revoked (effective separation or an administrator decision)
 * (StaffAccessService). Ids and a reason code only — the free-text reason stays in the audit log.
 */
class EmployeeAccessRevoked implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly ?int $employeeId,
        public readonly string $source,
        public readonly ?int $actorId,
    ) {}
}
