<?php

namespace App\Models;

use App\Enums\IncentiveAdjustmentType;
use Database\Factories\RecruiterIncentiveAdjustmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable correction/reversal against a calculation — never edits the original amount
 * (Section 28: an already-approved incentive is never silently overwritten).
 */
#[Fillable(['recruiter_incentive_calculation_id', 'adjustment_type', 'amount_delta', 'reason', 'created_by'])]
class RecruiterIncentiveAdjustment extends Model
{
    /** @use HasFactory<RecruiterIncentiveAdjustmentFactory> */
    use HasFactory;

    public const ?string UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'adjustment_type' => IncentiveAdjustmentType::class,
            'amount_delta' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<RecruiterIncentiveCalculation, $this>
     */
    public function calculation(): BelongsTo
    {
        return $this->belongsTo(RecruiterIncentiveCalculation::class, 'recruiter_incentive_calculation_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
