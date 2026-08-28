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
#[Fillable(['candidate_application_id', 'previous_stage', 'new_stage', 'changed_by', 'remarks'])]
class CandidateStageHistory extends Model
{
    public const ?string UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'previous_stage' => CandidateStage::class,
            'new_stage' => CandidateStage::class,
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
     * @return BelongsTo<Employee, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'changed_by');
    }
}
