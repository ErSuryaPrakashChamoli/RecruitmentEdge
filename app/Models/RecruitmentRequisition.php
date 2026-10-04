<?php

namespace App\Models;

use App\Enums\CandidateStage;
use App\Enums\EmploymentType;
use App\Enums\Entitlement;
use App\Enums\JoiningStatus;
use App\Enums\Priority;
use App\Enums\RequisitionStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GuardsLifecycleAttributes;
use App\Models\Concerns\ReferencesActiveMasterData;
use App\Services\Entitlements\EntitlementService;
use App\Services\HierarchyService;
use App\Services\Metrics\MetricPeriod;
use Carbon\CarbonImmutable;
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
    use BelongsToTenant, GuardsLifecycleAttributes, HasFactory, ReferencesActiveMasterData, SoftDeletes;

    /**
     * @return array<int, string>
     */
    public function lifecycleAttributes(): array
    {
        return ['status', 'closed_at'];
    }

    public function lifecycleOwner(): string
    {
        return 'RequisitionApprovalService';
    }

    /**
     * SaaS-3: a new (or restored) active requisition must fit the tenant's plan. The application's
     * paths take the limit atomically (RequisitionService); this backstop refuses any path that
     * forgot to.
     */
    protected static function booted(): void
    {
        static::creating(function (self $requisition): void {
            if (! in_array($requisition->status, [RequisitionStatus::Closed, RequisitionStatus::Cancelled], true)) {
                app(EntitlementService::class)->assertCanAdd(Entitlement::RequisitionsActiveMax);
            }
        });

        static::restoring(function (self $requisition): void {
            if (! in_array($requisition->status, [RequisitionStatus::Closed, RequisitionStatus::Cancelled], true)) {
                app(EntitlementService::class)->assertCanAdd(Entitlement::RequisitionsActiveMax);
            }
        });
    }

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
            'closed_at' => 'datetime',
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
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class)->withTrashed();
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
            ->using(TenantPivot::class)
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
     * Pipeline stages at or beyond Joined (Documents Completed, Onboarding Completed), derived from
     * the canonical CandidateStage order. Phase 8.3: no longer the definition of a filled opening
     * (see filledOpeningsCount) — kept for stage-based exclusions such as Talent Rediscovery.
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

    /**
     * Filled openings (Phase 8.3 definition): applications whose joining record is marked Joined.
     * The joining record is the completed-hire anchor — an application sitting at the Joined
     * pipeline stage without one does not count. Uses a preloaded `filled_openings_count` (see
     * scopeWithFilledOpeningsCount) when present so list tables don't run one query per row.
     */
    public function filledOpeningsCount(): int
    {
        if (array_key_exists('filled_openings_count', $this->attributes)) {
            return (int) $this->attributes['filled_openings_count'];
        }

        return $this->applications()->whereHas('joining', fn (Builder $joining) => $joining->where('status', JoiningStatus::Joined->value))->count();
    }

    /**
     * @param  Builder<RecruitmentRequisition>  $query
     */
    #[Scope]
    protected function withFilledOpeningsCount(Builder $query): void
    {
        $query->withCount([
            'applications as filled_openings_count' => fn (Builder $applications) => $applications->whereHas('joining', fn (Builder $joining) => $joining->where('status', JoiningStatus::Joined->value)),
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

    /**
     * Phase 8.5 (requisition ageing): whole calendar days, in the business timezone, from the opening
     * date (or creation date) to the close date — or today while the requisition is still open. Never
     * negative: a requisition whose opening date is still ahead is 0 days old (isNotYetOpen()). A
     * requisition closed before Phase 8.5 has no close date and keeps ageing to today
     * (hasUnknownCloseDate()).
     */
    public function ageingInDays(): int
    {
        $opened = CarbonImmutable::parse(($this->opening_date ?? $this->created_at)->toDateString(), MetricPeriod::timezone());
        $until = $this->closed_at !== null
            ? CarbonImmutable::parse(MetricPeriod::businessDate($this->closed_at), MetricPeriod::timezone())
            : MetricPeriod::now()->startOfDay();

        return max(0, (int) $opened->diffInDays($until, false));
    }

    public function isNotYetOpen(): bool
    {
        return $this->opening_date !== null && $this->opening_date->toDateString() > MetricPeriod::now()->toDateString();
    }

    public function hasUnknownCloseDate(): bool
    {
        return in_array($this->status, [RequisitionStatus::Closed, RequisitionStatus::Cancelled], true) && $this->closed_at === null;
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
            'location_id' => Location::class,
        ];
    }
}
