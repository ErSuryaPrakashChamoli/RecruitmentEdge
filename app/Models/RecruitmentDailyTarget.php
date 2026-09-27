<?php

namespace App\Models;

use App\Enums\TargetMetric;
use App\Enums\TargetPeriodType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\ReferencesActiveMasterData;
use Database\Factories\RecruitmentDailyTargetFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Exactly one of employee_id/department_id/designation_id must be set, giving the target's
 * scope (recruiter-specific targets win over designation targets, which win over department
 * targets — see TargetResolutionService). Enforced by form validation and a saving guard here,
 * not a DB constraint.
 */
#[Fillable([
    'employee_id',
    'department_id',
    'designation_id',
    'metric',
    'period_type',
    'target_value',
    'effective_from',
    'effective_to',
    'created_by',
])]
class RecruitmentDailyTarget extends Model
{
    /** @use HasFactory<RecruitmentDailyTargetFactory> */
    use Auditable, HasFactory, ReferencesActiveMasterData;

    protected function casts(): array
    {
        return [
            'metric' => TargetMetric::class,
            'period_type' => TargetPeriodType::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $target): void {
            if (! $target->hasExactlyOneScope()) {
                throw new DomainException('A target must be scoped to exactly one of a recruiter, a designation, or a department.');
            }

            // Phase 8.6 (D8.6-016): ranges run forwards, and one scope has one target per metric and
            // period type at a time — overlapping targets would make resolution ambiguous.
            if ($target->effective_to !== null && $target->effective_from !== null && $target->effective_to->lt($target->effective_from)) {
                throw new DomainException('The effective-to date cannot be before the effective-from date.');
            }

            if ($target->overlapping()->exists()) {
                throw new DomainException('Another target for the same scope, metric and period already covers part of these dates. End that target first.');
            }
        });
    }

    public function hasExactlyOneScope(): bool
    {
        return collect([$this->employee_id, $this->designation_id, $this->department_id])
            ->filter(fn (mixed $id): bool => filled($id))
            ->count() === 1;
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        // Phase 8.4: a deleted employee's records keep their attribution (and stay visible to the
        // managers above them) — historical ownership is never silently dropped.
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Designation, $this>
     */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    /**
     * Phase 8.6 (D8.6-005): master data taken up by this record must be in service.
     *
     * @return array<string, class-string<Model>>
     */
    public function activeMasterDataReferences(): array
    {
        return [
            'department_id' => Department::class,
            'designation_id' => Designation::class,
        ];
    }

    /**
     * Other targets with the same scope, metric and period type whose dates intersect this one.
     *
     * @return Builder<self>
     */
    public function overlapping(): Builder
    {
        return self::query()
            ->when($this->exists, fn (Builder $query) => $query->whereKeyNot($this->getKey()))
            ->where('metric', $this->metric)
            ->where('period_type', $this->period_type)
            ->where(fn (Builder $query) => $this->employee_id !== null ? $query->where('employee_id', $this->employee_id) : $query->whereNull('employee_id'))
            ->where(fn (Builder $query) => $this->designation_id !== null ? $query->where('designation_id', $this->designation_id) : $query->whereNull('designation_id'))
            ->where(fn (Builder $query) => $this->department_id !== null ? $query->where('department_id', $this->department_id) : $query->whereNull('department_id'))
            ->when($this->effective_to !== null, fn (Builder $query) => $query->whereDate('effective_from', '<=', $this->effective_to))
            ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $this->effective_from));
    }
}
