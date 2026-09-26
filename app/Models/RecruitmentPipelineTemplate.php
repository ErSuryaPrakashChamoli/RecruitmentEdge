<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\RecruitmentPipelineTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable, ordered hiring pipeline (e.g. "Technology Hiring"). `version` is bumped by
 * PipelineTemplateService whenever the stage list changes; requisitions record the version they
 * were snapshotted from (recruitment_requisitions.pipeline_template_version). Only
 * PipelineTemplateService writes the stage list, the version, and the single default flag.
 */
#[Fillable(['name', 'slug', 'description', 'is_active', 'is_default', 'cloned_from_id', 'created_by'])]
class RecruitmentPipelineTemplate extends Model
{
    /** @use HasFactory<RecruitmentPipelineTemplateFactory> */
    use Auditable, HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'version' => 1,
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return HasMany<RecruitmentPipelineTemplateStage, $this>
     */
    public function templateStages(): HasMany
    {
        return $this->hasMany(RecruitmentPipelineTemplateStage::class, 'pipeline_template_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * @return HasMany<RecruitmentRequisition, $this>
     */
    public function requisitions(): HasMany
    {
        return $this->hasMany(RecruitmentRequisition::class, 'pipeline_template_id');
    }

    /**
     * @return BelongsTo<RecruitmentPipelineTemplate, $this>
     */
    public function clonedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'cloned_from_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    /**
     * @param  Builder<RecruitmentPipelineTemplate>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @return array<int, string>
     */
    public static function activeOptions(): array
    {
        return self::query()->active()->orderByDesc('is_default')->orderBy('name')->pluck('name', 'id')->all();
    }
}
