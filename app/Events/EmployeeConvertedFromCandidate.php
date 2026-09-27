<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 8.2: an employee record was created from a joined candidate
 * (EmployeeConversionService::convert). Ids only. It links the employee to the hiring snapshot;
 * it is not the hire anchor — the joining record is.
 */
class EmployeeConvertedFromCandidate implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $candidateId,
        public readonly int $applicationId,
        public readonly ?int $requisitionId,
        public readonly int $employeeId,
        public readonly string $convertedAt,
    ) {}
}
