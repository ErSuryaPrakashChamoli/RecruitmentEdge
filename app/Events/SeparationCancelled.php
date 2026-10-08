<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 8.4: a separation was cancelled (EmployeeLifecycleService::cancelSeparation). Ids only.
 * $wasEffective: it had already taken effect — employment is Active again but access stays revoked
 * until an administrator restores it deliberately.
 */
class SeparationCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $employeeId,
        public readonly int $separationId,
        public readonly bool $wasEffective,
        public readonly ?int $actorId,
    ) {}
}
