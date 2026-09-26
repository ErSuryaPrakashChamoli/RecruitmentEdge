<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phase 8.4: a separation took effect — employment is Separated and the login (if any) revoked
 * (EmployeeLifecycleService::applyEffectiveSeparation). Ids only; starts the ownership handoff.
 */
class EmployeeSeparated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $employeeId,
        public readonly int $separationId,
        public readonly ?int $userId,
        public readonly string $lastWorkingDay,
    ) {}
}
