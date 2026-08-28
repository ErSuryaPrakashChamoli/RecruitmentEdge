<?php

namespace App\Models;

use Database\Factories\RecruitmentManualActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Free-form, self-reported bulk activity for legitimate offline work with no per-candidate system
 * record (e.g. a field walk-in drive). Deliberately NOT read by performance or incentive
 * calculations (Section 46) — it exists for HR visibility only. If a metric ever needs to draw on
 * this table, that must be an explicit, documented decision in the relevant calculator, not a
 * silent default.
 */
#[Fillable(['recruiter_id', 'activity_date', 'metric', 'count', 'remarks', 'created_by'])]
class RecruitmentManualActivity extends Model
{
    /** @use HasFactory<RecruitmentManualActivityFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'activity_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function recruiter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'recruiter_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
