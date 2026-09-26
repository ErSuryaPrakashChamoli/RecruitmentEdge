<?php

namespace App\Events;

use App\Enums\RequisitionStatus;
use App\Models\Employee;
use App\Models\RecruitmentRequisition;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A requisition status transition made through RequisitionApprovalService::moveTo(), after it
 * commits (Phase 7 — Hiring Memory captures the requisition outcome on Closed/Cancelled; also an
 * automation trigger).
 */
class RequisitionStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly RecruitmentRequisition $requisition,
        public readonly RequisitionStatus $from,
        public readonly RequisitionStatus $to,
        public readonly ?Employee $actor = null,
    ) {}
}
