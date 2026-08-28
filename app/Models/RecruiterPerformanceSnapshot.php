<?php

namespace App\Models;

use Database\Factories\RecruiterPerformanceSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A computed, recomputable cache of a performance calculation for one recruiter/period — never a
 * source of truth itself. Always recreated via PerformanceEngine::snapshotFor(), never edited by
 * hand.
 */
#[Fillable(['employee_id', 'period_start', 'period_end', 'score', 'breakdown', 'computed_at'])]
class RecruiterPerformanceSnapshot extends Model
{
    /** @use HasFactory<RecruiterPerformanceSnapshotFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'score' => 'decimal:2',
            'breakdown' => 'array',
            'computed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
