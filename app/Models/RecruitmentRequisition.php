<?php

namespace App\Models;

use App\Enums\CandidateStage;
use App\Enums\EmploymentType;
use App\Enums\Priority;
use App\Enums\RequisitionStatus;
use Database\Factories\RecruitmentRequisitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'code',
    'department_id',
    'designation_id',
    'location_id',
    'openings',
    'employment_type',
    'salary_min',
    'salary_max',
    'experience_min',
    'experience_max',
    'qualification',
    'skills',
    'shift',
    'reporting_manager_id',
    'hiring_manager_id',
    'assistant_manager_id',
    'manager_id',
    'vp_hr_id',
    'priority',
    'target_joining_date',
    'opening_date',
    'closing_date',
    'remarks',
    'status',
    'created_by',
])]
class RecruitmentRequisition extends Model
{
    /** @use HasFactory<RecruitmentRequisitionFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'employment_type' => EmploymentType::class,
            'priority' => Priority::class,
            'status' => RequisitionStatus::class,
            'skills' => 'array',
            'salary_min' => 'decimal:2',
            'salary_max' => 'decimal:2',
            'experience_min' => 'decimal:1',
            'experience_max' => 'decimal:1',
            'target_joining_date' => 'date',
            'opening_date' => 'date',
            'closing_date' => 'date',
        ];
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
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function reportingManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reporting_manager_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function hiringManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'hiring_manager_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function assistantManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assistant_manager_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function vpHr(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'vp_hr_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    /**
     * @return BelongsToMany<Employee, $this>
     */
    public function recruiters(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'recruitment_requisition_recruiters', 'requisition_id', 'employee_id')
            ->withPivot('assigned_at');
    }

    /**
     * @return HasMany<RecruitmentRequisitionApproval, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(RecruitmentRequisitionApproval::class, 'requisition_id')->latest('created_at');
    }

    /**
     * @return HasMany<CandidateApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(CandidateApplication::class, 'requisition_id');
    }

    public function filledOpeningsCount(): int
    {
        return $this->applications()->where('current_stage', CandidateStage::Joined->value)->count();
    }

    public function remainingOpenings(): int
    {
        return max(0, $this->openings - $this->filledOpeningsCount());
    }

    public function ageingInDays(): int
    {
        return (int) ($this->opening_date ?? $this->created_at)->diffInDays(now());
    }

    /**
     * Every employee with a stake in this requisition (assigned recruiters plus the named
     * management chain on the requisition itself) — used by RecruitmentRequisitionPolicy /
     * RecruitmentRequisitionResource to decide hierarchy visibility.
     *
     * @return array<int, int>
     */
    public function involvedEmployeeIds(): array
    {
        return $this->recruiters()->pluck('employees.id')
            ->merge([
                $this->reporting_manager_id,
                $this->hiring_manager_id,
                $this->assistant_manager_id,
                $this->manager_id,
                $this->vp_hr_id,
                $this->created_by,
            ])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
