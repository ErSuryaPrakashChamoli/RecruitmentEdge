<?php

namespace App\Models;

use App\Enums\RecruitmentCostStatus;
use App\Enums\RecruitmentCostType;
use Database\Factories\RecruitmentCostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Section 34: feeds CostPerHireService and RecruitmentAnalyticsService::sourceAnalytics()'s
 * source-ROI figures. Scoped to any combination of requisition/department/source/location, or
 * none (org-wide overhead) — nothing is enforced at the DB level since org-wide costs are
 * legitimate too.
 */
#[Fillable(['requisition_id', 'department_id', 'source_id', 'location_id', 'cost_type', 'campaign', 'amount', 'status', 'incurred_on', 'remarks', 'created_by'])]
class RecruitmentCost extends Model
{
    /** @use HasFactory<RecruitmentCostFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'cost_type' => RecruitmentCostType::class,
            'status' => RecruitmentCostStatus::class,
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
     * @return BelongsTo<CandidateSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(CandidateSource::class, 'source_id');
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
