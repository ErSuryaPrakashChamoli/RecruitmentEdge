<?php

namespace App\Models;

use App\Enums\TargetMetric;
use App\Models\Concerns\Auditable;
use Database\Factories\RecruiterPerformanceRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A configurable weight applied to one metric's achievement % when computing a composite
 * performance score (Section 21) — see PerformanceEngine. Weights need not sum to exactly 100;
 * the engine normalizes by the sum of weights actually configured, so partial setup during
 * onboarding never produces a nonsensical score.
 */
#[Fillable(['metric', 'weightage', 'effective_from', 'effective_to', 'created_by'])]
class RecruiterPerformanceRule extends Model
{
    /** @use HasFactory<RecruiterPerformanceRuleFactory> */
    use Auditable, HasFactory;

    protected function casts(): array
    {
        return [
            'metric' => TargetMetric::class,
            'weightage' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
