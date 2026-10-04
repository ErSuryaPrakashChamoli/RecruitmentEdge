<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stage slot in a pipeline template. `sla_hours` / `is_skippable` override the library
 * stage's values for this template only (null = inherit).
 */
#[Fillable(['pipeline_template_id', 'recruitment_stage_id', 'sort_order', 'sla_hours', 'is_skippable'])]
class RecruitmentPipelineTemplateStage extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'sla_hours' => 'integer',
            'is_skippable' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<RecruitmentPipelineTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(RecruitmentPipelineTemplate::class, 'pipeline_template_id');
    }

    /**
     * @return BelongsTo<RecruitmentStage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(RecruitmentStage::class, 'recruitment_stage_id');
    }
}
