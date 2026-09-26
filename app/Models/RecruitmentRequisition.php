<?php

namespace App\Models;

use App\Enums\CandidateStage;
use App\Enums\EmploymentType;
use App\Enums\Priority;
use App\Enums\RequisitionStatus;
use App\Services\HierarchyService;
use Database\Factories\RecruitmentRequisitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
            'pipeline_template_version' => 'integer',
            'pipeline_applied_at' => 'datetime',
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
     * The template this requisition's pipeline was snapshotted from (informational — the snapshot
     * in pipelineStages() is what governs the requisition).
     *
     * @return BelongsTo<RecruitmentPipelineTemplate, $this>
     */
    public function pipelineTemplate(): BelongsTo
    {
        return $this->belongsTo(RecruitmentPipelineTemplate::class, 'pipeline_template_id');
    }

    /**
     * The requisition's current (non-superseded) pipeline snapshot, in order.
     *
     * @return HasMany<RequisitionPipelineStage, $this>
     */
    public function pipelineStages(): HasMany
    {
        return $this->hasMany(RequisitionPipelineStage::class, 'requisition_id')->current();
    }

    public function hasConfiguredPipeline(): bool
    {
        return $this->pipeline_applied_at !== null;
    }

    /**
     * @return HasOne<JobPosting, $this>
     */
    public function jobPosting(): HasOne
    {
        return $this->hasOne(JobPosting::class, 'requisition_id');
    }

    /**
     * @return HasMany<CandidateApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(CandidateApplication::class, 'requisition_id');
    }

    /**
     * Stages that count an opening as filled: Joined or any later stage (Documents Completed,
     * Onboarding Completed), derived from the canonical CandidateStage order.
     *
     * @return array<int, string>
     */
    public static function filledStageValues(): array
    {
        return collect(CandidateStage::cases())
            ->filter(fn (CandidateStage $stage): bool => $stage->order() >= CandidateStage::Joined->order())
            ->map(fn (CandidateStage $stage): string => $stage->value)
            ->values()
            ->all();
    }

    /**
     * Uses a preloaded `filled_openings_count` (see scopeWithFilledOpeningsCount) when present so
     * list tables don't run one count query per row.
     */
    /**
     * Requisitions a user may see: those where someone in their hierarchy is a reporting, hiring,
     * assistant or line manager, VP HR, creator or assigned recruiter (all, with
     * hierarchy.view-all). The one definition of requisition visibility — the Filament resource and
     * EDGE Intelligence both use it.
     *
     * @param  Builder<RecruitmentRequisition>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);

        if ($visibleIds === null) {
            return;
        }

        $query->where(function (Builder $q) use ($visibleIds): void {
            $q->whereIn('reporting_manager_id', $visibleIds)
                ->orWhereIn('hiring_manager_id', $visibleIds)
                ->orWhereIn('assistant_manager_id', $visibleIds)
                ->orWhereIn('manager_id', $visibleIds)
                ->orWhereIn('vp_hr_id', $visibleIds)
                ->orWhereIn('created_by', $visibleIds)
                ->orWhereHas('recruiters', fn (Builder $r) => $r->whereIn('employees.id', $visibleIds));
        });
    }

    public function filledOpeningsCount(): int
    {
        if (array_key_exists('filled_openings_count', $this->attributes)) {
            return (int) $this->attributes['filled_openings_count'];
        }

        return $this->applications()->whereIn('current_stage', self::filledStageValues())->count();
    }

    /**
     * @param  Builder<RecruitmentRequisition>  $query
     */
    #[Scope]
    protected function withFilledOpeningsCount(Builder $query): void
    {
        $query->withCount([
            'applications as filled_openings_count' => fn (Builder $applications) => $applications->whereIn('current_stage', self::filledStageValues()),
        ]);
    }

    /**
     * New applications may only be raised against an Open requisition.
     */
    public function acceptsApplications(): bool
    {
        return $this->status === RequisitionStatus::Open;
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
