<?php

namespace App\Models;

use App\Enums\OutcomeCaptureMode;
use App\Enums\OutcomeCategory;
use App\Enums\OutcomeConfidence;
use App\Enums\OutcomeResult;
use App\Enums\OutcomeState;
use App\Enums\OutcomeType;
use Database\Factories\HiringOutcomeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Outcome Loop™ (Phase 8.2): one deterministic outcome or status observation, with its provenance
 * (source, rule version, observed_at) and confidence. Written only by OutcomeService. Immutable:
 * a correction or re-observation adds a new version that supersedes this one (is_current flips),
 * so every past calculation stays explainable.
 */
#[Fillable([
    'outcome_type', 'category', 'state', 'result', 'value', 'unit', 'confidence', 'capture_mode',
    'hiring_outcome_snapshot_id', 'candidate_application_id', 'requisition_id', 'employee_id', 'offer_id', 'candidate_joining_id',
    'observation_start', 'observation_end', 'observed_at', 'source_type', 'source_id', 'rule_version', 'details',
    'dedupe_key', 'version', 'supersedes_id', 'is_current', 'corrected_by', 'correction_reason',
])]
class HiringOutcome extends Model
{
    /** @use HasFactory<HiringOutcomeFactory> */
    use HasFactory;

    public const array MUTABLE = ['is_current', 'updated_at'];

    protected function casts(): array
    {
        return [
            'outcome_type' => OutcomeType::class,
            'category' => OutcomeCategory::class,
            'state' => OutcomeState::class,
            'result' => OutcomeResult::class,
            'value' => 'decimal:2',
            'confidence' => OutcomeConfidence::class,
            'capture_mode' => OutcomeCaptureMode::class,
            'observation_start' => 'date',
            'observation_end' => 'date',
            'observed_at' => 'datetime',
            'details' => 'array',
            'is_current' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $outcome): void {
            if (array_diff(array_keys($outcome->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException('Outcomes are immutable — record a new version through OutcomeService.');
            }
        });
    }

    /**
     * @param  Builder<HiringOutcome>  $query
     */
    #[Scope]
    protected function current(Builder $query): void
    {
        $query->where('is_current', true);
    }

    /**
     * @return BelongsTo<HiringOutcomeSnapshot, $this>
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(HiringOutcomeSnapshot::class, 'hiring_outcome_snapshot_id');
    }

    /**
     * @return BelongsTo<CandidateApplication, $this>
     */
    public function candidateApplication(): BelongsTo
    {
        return $this->belongsTo(CandidateApplication::class);
    }

    /**
     * @return BelongsTo<RecruitmentRequisition, $this>
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRequisition::class, 'requisition_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Offer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /**
     * @return BelongsTo<HiringOutcome, $this>
     */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function corrector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
