<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\DescribesPipelineStage;
use Database\Factories\RecruitmentStageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A configurable hiring stage in the organisation's stage library (Phase 4 Stage Builder). Stages
 * are composed into RecruitmentPipelineTemplate rows and snapshotted per requisition as
 * RequisitionPipelineStage, so editing a stage here never rewrites an in-flight requisition.
 *
 * `milestone` maps the stage onto the canonical CandidateStage order: that is what
 * CandidateApplication.current_stage stores, so every existing funnel, SLA, target and incentive
 * query keeps working unchanged for custom stages. System stages (is_system) are the 18 canonical
 * stages seeded by StageConfigurationService::ensureSystemStages() — they can be renamed and
 * reconfigured but their code and milestone are fixed. Changes are audited via Auditable (there is
 * no dedicated history table for stage configuration).
 */
#[Fillable([
    'name',
    'code',
    'description',
    'stage_type',
    'milestone',
    'icon',
    'color',
    'sla_hours',
    'requires_candidate_action',
    'requires_recruiter_action',
    'is_interview_stage',
    'is_offer_stage',
    'is_joining_stage',
    'is_terminal',
    'is_skippable',
    'allows_rejection',
    'allows_dropout',
    'requirements',
    'candidate_visible',
    'candidate_label',
    'sort_order',
    'is_active',
    'created_by',
])]
class RecruitmentStage extends Model
{
    /** @use HasFactory<RecruitmentStageFactory> */
    use Auditable, BelongsToTenant, DescribesPipelineStage, HasFactory;

    protected function casts(): array
    {
        return [
            ...$this->stageSettingCasts(),
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    /**
     * Stages this stage may move to directly. Empty means the default forward rule applies.
     *
     * @return BelongsToMany<RecruitmentStage, $this>
     */
    public function allowedNextStages(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'recruitment_stage_transitions', 'from_stage_id', 'to_stage_id')
            ->using(TenantPivot::class)
            ->withPivot('requires_remarks')
            ->withTimestamps();
    }

    /**
     * @return HasMany<RecruitmentPipelineTemplateStage, $this>
     */
    public function templateStages(): HasMany
    {
        return $this->hasMany(RecruitmentPipelineTemplateStage::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    /**
     * @param  Builder<RecruitmentStage>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<RecruitmentStage>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }
}
