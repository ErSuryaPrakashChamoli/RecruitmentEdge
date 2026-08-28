<?php

namespace App\Services;

use App\Enums\RequisitionStatus;
use App\Models\Employee;
use App\Models\RecruitmentRequisition;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The only code path allowed to change a requisition's status. Every change is written
 * atomically with a permanent `recruitment_requisition_approvals` row, so the requisition's
 * approval/lifecycle trail is never lost even though the requisition itself only stores its
 * current status.
 */
class RequisitionApprovalService
{
    /**
     * @var array<string, array<int, string>>
     */
    private const array ALLOWED_TRANSITIONS = [
        'draft' => ['pending_approval', 'cancelled'],
        'pending_approval' => ['approved', 'draft', 'cancelled'],
        'approved' => ['open', 'cancelled'],
        'open' => ['on_hold', 'closed', 'cancelled'],
        'on_hold' => ['open', 'closed', 'cancelled'],
        'closed' => [],
        'cancelled' => [],
    ];

    public function submitForApproval(RecruitmentRequisition $requisition, ?Employee $actor = null, ?string $remarks = null): RecruitmentRequisition
    {
        return $this->moveTo($requisition, RequisitionStatus::PendingApproval, $actor, $remarks);
    }

    public function approve(RecruitmentRequisition $requisition, ?Employee $actor = null, ?string $remarks = null): RecruitmentRequisition
    {
        return $this->moveTo($requisition, RequisitionStatus::Approved, $actor, $remarks);
    }

    public function sendBackToDraft(RecruitmentRequisition $requisition, ?Employee $actor = null, ?string $remarks = null): RecruitmentRequisition
    {
        return $this->moveTo($requisition, RequisitionStatus::Draft, $actor, $remarks);
    }

    public function open(RecruitmentRequisition $requisition, ?Employee $actor = null, ?string $remarks = null): RecruitmentRequisition
    {
        return $this->moveTo($requisition, RequisitionStatus::Open, $actor, $remarks);
    }

    public function hold(RecruitmentRequisition $requisition, ?Employee $actor = null, ?string $remarks = null): RecruitmentRequisition
    {
        return $this->moveTo($requisition, RequisitionStatus::OnHold, $actor, $remarks);
    }

    public function close(RecruitmentRequisition $requisition, ?Employee $actor = null, ?string $remarks = null): RecruitmentRequisition
    {
        return $this->moveTo($requisition, RequisitionStatus::Closed, $actor, $remarks);
    }

    public function cancel(RecruitmentRequisition $requisition, ?Employee $actor = null, ?string $remarks = null): RecruitmentRequisition
    {
        return $this->moveTo($requisition, RequisitionStatus::Cancelled, $actor, $remarks);
    }

    /**
     * @return array<int, RequisitionStatus>
     */
    public function allowedNextStatuses(RecruitmentRequisition $requisition): array
    {
        return array_map(
            RequisitionStatus::from(...),
            self::ALLOWED_TRANSITIONS[$requisition->status->value],
        );
    }

    public function moveTo(RecruitmentRequisition $requisition, RequisitionStatus $to, ?Employee $actor = null, ?string $remarks = null): RecruitmentRequisition
    {
        $from = $requisition->status;

        if (! in_array($to->value, self::ALLOWED_TRANSITIONS[$from->value], true)) {
            throw new DomainException("Cannot move a requisition from {$from->label()} to {$to->label()}.");
        }

        return DB::transaction(function () use ($requisition, $from, $to, $actor, $remarks): RecruitmentRequisition {
            $requisition->forceFill(['status' => $to])->save();

            $requisition->statusHistory()->create([
                'from_status' => $from,
                'to_status' => $to,
                'changed_by' => $actor?->id,
                'remarks' => $remarks,
            ]);

            return $requisition;
        });
    }
}
