<?php

namespace App\Models;

use App\Enums\RecruitmentCostType;
use Database\Factories\RecruitmentCostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Section 34: feeds CostPerHireService. Scoped to a requisition, a department, or neither
 * (org-wide overhead) — at least one of requisition_id/department_id should normally be set for
 * the cost to be attributable, but that isn't enforced at the DB level since org-wide costs are
 * legitimate too.
 */
#[Fillable(['requisition_id', 'department_id', 'cost_type', 'amount', 'incurred_on', 'remarks', 'created_by'])]
class RecruitmentCost extends Model
{
    /** @use HasFactory<RecruitmentCostFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'cost_type' => RecruitmentCostType::class,
            'amount' => 'decimal:2',
            'incurred_on' => 'date',
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
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
