<?php

namespace App\Models;

use App\Enums\TargetMetric;
use App\Enums\TargetPeriodType;
use App\Models\Concerns\Auditable;
use Database\Factories\RecruitmentDailyTargetFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
    use Auditable, HasFactory;

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
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<Designation, $this>
     */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
