<?php

namespace App\Services;

use App\Enums\RequisitionStatus;
use App\Models\Employee;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The only code path allowed to change a requisition's status. Every change is written
 * atomically with a permanent `recruitment_requisition_approvals` row, so the requisition's
 * approval/lifecycle trail is never lost even though the requisition itself only stores its
 * current status.
 *
 * Approval decisions (approving, or sending a pending requisition back to draft) are enforced
 * here — not just in the UI — so every write path requires the `requisitions.approve` permission.
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

    /**
     * Target statuses that always need a written reason on the history row.
     *
     * @var array<int, string>
     */
    private const array REMARKS_REQUIRED_STATUSES = ['on_hold', 'closed', 'cancelled'];

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

    public function resume(RecruitmentRequisition $requisition, ?Employee $actor = null, ?string $remarks = null): RecruitmentRequisition
    {
        return $this->moveTo($requisition, RequisitionStatus::Open, $actor, $remarks);
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

    /**
     * Whether moving from $from to $to is an approval decision (approve, or send a pending
     * requisition back to draft) that needs the `requisitions.approve` permission.
     */
    public function requiresApprovalPermission(RequisitionStatus $from, RequisitionStatus $to): bool
    {
        return $to === RequisitionStatus::Approved
            || ($from === RequisitionStatus::PendingApproval && $to === RequisitionStatus::Draft);
    }

    public function requiresRemarks(RequisitionStatus $from, RequisitionStatus $to): bool
    {
        return in_array($to->value, self::REMARKS_REQUIRED_STATUSES, true)
            || ($from === RequisitionStatus::PendingApproval && $to === RequisitionStatus::Draft);
    }

    public function moveTo(RecruitmentRequisition $requisition, RequisitionStatus $to, ?Employee $actor = null, ?string $remarks = null): RecruitmentRequisition
    {
        $from = $requisition->status;

        if (! in_array($to->value, self::ALLOWED_TRANSITIONS[$from->value], true)) {
            throw new DomainException("Cannot move a requisition from {$from->label()} to {$to->label()}.");
        }

        if ($this->requiresApprovalPermission($from, $to) && ! $this->actingUserCanApprove($actor)) {
            throw new DomainException("You do not have permission to move a requisition from {$from->label()} to {$to->label()}.");
        }

        if ($this->requiresRemarks($from, $to) && blank($remarks)) {
            throw new DomainException("A reason is required to move a requisition to {$to->label()}.");
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

    /**
     * The acting user is the actor employee's login, falling back to the authenticated user (e.g.
     * a CHRO account with no linked employee record).
     */
    private function actingUserCanApprove(?Employee $actor): bool
    {
        /** @var User|null $user */
        $user = $actor?->user ?? auth()->user();

        return $user instanceof User && $user->can('requisitions.approve');
    }
}
