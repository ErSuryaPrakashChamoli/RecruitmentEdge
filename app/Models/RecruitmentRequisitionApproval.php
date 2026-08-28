<?php

namespace App\Models;

use App\Enums\RequisitionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable log of every status transition a requisition goes through (not just
 * approve/reject) — see RequisitionApprovalService, the only writer of this table.
 */
#[Fillable(['requisition_id', 'from_status', 'to_status', 'changed_by', 'remarks'])]
class RecruitmentRequisitionApproval extends Model
{
    public const ?string UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'from_status' => RequisitionStatus::class,
            'to_status' => RequisitionStatus::class,
        ];
    }

    /**
     * @return BelongsTo<RecruitmentRequisition, $this>
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRequisition::class, 'requisition_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'changed_by');
    }
}
