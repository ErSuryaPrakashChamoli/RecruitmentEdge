<?php

namespace App\Models;

use App\Enums\TargetMetric;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\RecruiterPerformanceRuleFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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
    use Auditable, BelongsToTenant, HasFactory;

    protected static function booted(): void
    {
        // Phase 8.6 (D8.6-016): a metric is weighted once at any date — overlapping rules would
        // double-count it in the composite score.
        static::saving(function (self $rule): void {
            if ($rule->effective_to !== null && $rule->effective_from !== null && $rule->effective_to->lt($rule->effective_from)) {
                throw new DomainException('The effective-to date cannot be before the effective-from date.');
            }

            $overlaps = self::query()
                ->when($rule->exists, fn (Builder $query) => $query->whereKeyNot($rule->getKey()))
                ->where('metric', $rule->metric)
                ->when($rule->effective_to !== null, fn (Builder $query) => $query->whereDate('effective_from', '<=', $rule->effective_to))
                ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $rule->effective_from))
                ->exists();

            if ($overlaps) {
                throw new DomainException('Another performance rule already weights this metric for part of these dates. End that rule first.');
            }
        });
    }

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
