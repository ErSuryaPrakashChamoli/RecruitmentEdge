<?php

namespace App\Models;

use App\Enums\MetricAccountability;
use App\Enums\TargetMetric;
use App\Services\PerformanceEngine;
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

    /**
     * @return array{metric: string, weight?: float, target: int|null, actual: int, achievement: float|null}|null
     */
    public function breakdownFor(TargetMetric $metric): ?array
    {
        return collect($this->breakdown ?? [])->firstWhere('metric', $metric->value);
    }

    /**
     * @return array<string, array{accountability: MetricAccountability, rows: list<array{metric: string, weight?: float, target: int|null, actual: int, achievement: float|null}>, measured: int, met: int, average_achievement: float|null}>
     */
    public function accountabilitySummary(): array
    {
        return PerformanceEngine::summarizeByAccountability($this->breakdown ?? []);
    }

    /**
     * Metrics that reached 100% achievement, out of those with a resolvable target.
     *
     * @return array{met: int, measured: int}
     */
    public function metricsMetCount(): array
    {
        $summary = collect($this->accountabilitySummary());

        return [
            'met' => $summary->sum('met'),
            'measured' => $summary->sum('measured'),
        ];
    }
}
