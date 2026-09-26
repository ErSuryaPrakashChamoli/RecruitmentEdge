<?php

namespace App\Models;

use App\Enums\CandidateStage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable record of every stage a candidate's application passed through — never updated or
 * deleted. See StageTransitionService, the only writer of this table.
 */
#[Fillable(['candidate_application_id', 'previous_stage', 'new_stage', 'previous_pipeline_stage_id', 'new_pipeline_stage_id', 'changed_by', 'remarks', 'is_override'])]
class CandidateStageHistory extends Model
{
    public const ?string UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'previous_stage' => CandidateStage::class,
            'new_stage' => CandidateStage::class,
            'is_override' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<CandidateApplication, $this>
     */
    public function candidateApplication(): BelongsTo
    {
        return $this->belongsTo(CandidateApplication::class);
    }

    /**
     * @return BelongsTo<RequisitionPipelineStage, $this>
     */
    public function previousPipelineStage(): BelongsTo
    {
        return $this->belongsTo(RequisitionPipelineStage::class, 'previous_pipeline_stage_id');
    }

    /**
     * @return BelongsTo<RequisitionPipelineStage, $this>
     */
    public function newPipelineStage(): BelongsTo
    {
        return $this->belongsTo(RequisitionPipelineStage::class, 'new_pipeline_stage_id');
    }

    /**
     * The configured stage name when the move happened on a configured pipeline, else the
     * canonical milestone label.
     */
    public function newStageLabel(): string
    {
        return $this->newPipelineStage?->name ?? $this->new_stage->label();
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'changed_by');
    }
}
