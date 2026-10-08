<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One explicitly allowed move between two library stages. Written only by
 * StageConfigurationService::syncTransitions(), which also audits the change.
 */
#[Fillable(['from_stage_id', 'to_stage_id', 'requires_remarks'])]
class RecruitmentStageTransition extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'requires_remarks' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<RecruitmentStage, $this>
     */
    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(RecruitmentStage::class, 'from_stage_id');
    }

    /**
     * @return BelongsTo<RecruitmentStage, $this>
     */
    public function toStage(): BelongsTo
    {
        return $this->belongsTo(RecruitmentStage::class, 'to_stage_id');
    }
}
