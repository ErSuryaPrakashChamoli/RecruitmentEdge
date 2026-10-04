<?php

namespace App\Models;

use App\Enums\HiringRiskStatus;
use App\Enums\HiringRiskType;
use App\Enums\RiskSeverity;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\HiringRiskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Hiring Risk Radar™ entry (Phase 7). Detected and auto-resolved by HiringRiskRadar; acknowledged,
 * dismissed or resolved by people through the same service (audited). A risk is a warning with
 * evidence and a recommended action — never a hiring decision.
 */
#[Fillable([
    'type',
    'severity',
    'status',
    'requisition_id',
    'subject_type',
    'subject_id',
    'candidate_application_id',
    'owner_id',
    'title',
    'description',
    'recommended_action',
    'detector_version',
    'confidence',
    'open_key',
    'first_detected_at',
    'last_seen_at',
])]
class HiringRisk extends Model
{
    /** @use HasFactory<HiringRiskFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'type' => HiringRiskType::class,
            'severity' => RiskSeverity::class,
            'status' => HiringRiskStatus::class,
            'confidence' => 'float',
            'first_detected_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [HiringRiskStatus::Open, HiringRiskStatus::Acknowledged], true);
    }

    /**
     * @param  Builder<HiringRisk>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [HiringRiskStatus::Open, HiringRiskStatus::Acknowledged]);
    }

    /**
     * @return BelongsTo<RecruitmentRequisition, $this>
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRequisition::class, 'requisition_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
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
    public function owner(): BelongsTo
    {
        // Phase 8.4: a deleted employee's records keep their attribution (and stay visible to the
        // managers above them) — historical ownership is never silently dropped.
        return $this->belongsTo(Employee::class, 'owner_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /**
     * @return BelongsTo<RecruiterAction, $this>
     */
    public function recruiterAction(): BelongsTo
    {
        return $this->belongsTo(RecruiterAction::class);
    }

    /**
     * @return MorphMany<IntelligenceEvidence, $this>
     */
    public function evidence(): MorphMany
    {
        return $this->morphMany(IntelligenceEvidence::class, 'owner');
    }
}
