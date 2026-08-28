<?php

namespace App\Models;

use App\Enums\IncentiveCalculationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable log of every status transition a calculation goes through — see
 * IncentiveApprovalService, the only writer of this table.
 */
#[Fillable(['recruiter_incentive_calculation_id', 'from_status', 'to_status', 'changed_by', 'remarks'])]
class RecruiterIncentiveApproval extends Model
{
    public const ?string UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'from_status' => IncentiveCalculationStatus::class,
            'to_status' => IncentiveCalculationStatus::class,
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
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'changed_by');
    }
}
