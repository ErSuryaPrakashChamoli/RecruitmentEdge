<?php

namespace App\Models;

use App\Enums\EmploymentType;
use App\Enums\IncentiveTriggerEvent;
use App\Enums\TargetMetric;
use App\Models\Concerns\Auditable;
use Database\Factories\RecruitmentIncentiveRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Section 24: incentive rules are scoped by any combination of recruiter/department/designation/
 * employment type (all nullable — omit a scope to apply it broadly), fire on a configurable
 * trigger event, and pay according to whichever RecruitmentIncentiveSlab band the recruiter's
 * achievement on `achievement_metric` falls into. `retention_days`, when set, delays a
 * calculation's move out of Calculated until that many days after the triggering fact (Section 26).
 */
#[Fillable([
    'name',
    'trigger_event',
    'achievement_metric',
    'employee_id',
    'department_id',
    'designation_id',
    'employment_type',
    'retention_days',
    'effective_from',
    'effective_to',
    'is_active',
    'created_by',
])]
class RecruitmentIncentiveRule extends Model
{
    /** @use HasFactory<RecruitmentIncentiveRuleFactory> */
    use Auditable, HasFactory;

    protected function casts(): array
    {
        return [
            'trigger_event' => IncentiveTriggerEvent::class,
            'achievement_metric' => TargetMetric::class,
            'employment_type' => EmploymentType::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
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

    /**
     * @return HasMany<RecruitmentIncentiveSlab, $this>
     */
    public function slabs(): HasMany
    {
        return $this->hasMany(RecruitmentIncentiveSlab::class, 'incentive_rule_id')->orderBy('achievement_min');
    }

    /**
     * Whether this rule applies to the given recruiter, based on its configured scope columns —
     * an unset scope column matches everyone.
     */
    public function appliesTo(Employee $recruiter): bool
    {
        return ($this->employee_id === null || $this->employee_id === $recruiter->id)
            && ($this->department_id === null || $this->department_id === $recruiter->department_id)
            && ($this->designation_id === null || $this->designation_id === $recruiter->designation_id);
    }
}
