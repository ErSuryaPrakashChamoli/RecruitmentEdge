<?php

namespace App\Models;

use App\Enums\OutcomeInsightKind;
use App\Enums\OutcomeInsightStatus;
use Database\Factories\OutcomeInsightFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 8.2: an Outcome Loop learning insight — an observed association in completed hires, with
 * its evidence, sample size, period, confidence band and limitations. It changes nothing until a
 * person accepts it (OutcomeLearningService::review). Observational, never causal.
 */
#[Fillable([
    'kind', 'status', 'designation_id', 'requisition_id', 'subject_key', 'insight', 'suggested_change', 'evidence',
    'sample_size', 'sample_band', 'period_start', 'period_end', 'confidence', 'limitations', 'source_refs', 'rule_version',
    'ai_summary', 'ai_status', 'ai_model', 'ai_generated_at', 'reviewed_by', 'reviewed_at', 'review_reason', 'applied_ref',
    'dedupe_key', 'last_recalculated_at',
])]
class OutcomeInsight extends Model
{
    /** @use HasFactory<OutcomeInsightFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'kind' => OutcomeInsightKind::class,
            'status' => OutcomeInsightStatus::class,
            'evidence' => 'array',
            'source_refs' => 'array',
            'period_start' => 'date',
            'period_end' => 'date',
            'ai_generated_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'last_recalculated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Designation, $this>
     */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    /**
     * @return BelongsTo<RecruitmentRequisition, $this>
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRequisition::class, 'requisition_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
