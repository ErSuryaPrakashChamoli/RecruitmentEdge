<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\JoiningStatus;
use Database\Factories\CandidateJoiningFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'candidate_application_id',
    'offer_id',
    'expected_doj',
    'actual_doj',
    'status',
    'confirmed_at',
    'documents_status',
    'dropout_reason_id',
    'remarks',
    'created_by',
])]
class CandidateJoining extends Model
{
    /** @use HasFactory<CandidateJoiningFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => JoiningStatus::class,
            'documents_status' => DocumentStatus::class,
            'expected_doj' => 'date',
            'actual_doj' => 'date',
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * Section 17's traffic-light risk indicator, driven by the configurable
     * `joining_risk_followup_days` setting rather than a hard-coded threshold.
     */
    public function riskLevel(): string
    {
        if ($this->status === JoiningStatus::Joined) {
            return 'green';
        }

        if (in_array($this->status, [JoiningStatus::NoShow, JoiningStatus::Dropout], true)) {
            return 'red';
        }

        $daysToJoin = now()->startOfDay()->diffInDays($this->expected_doj->copy()->startOfDay(), false);

        if ($daysToJoin < 0) {
            return 'red';
        }

        if ($this->status === JoiningStatus::Confirmed) {
            return 'green';
        }

        $followUpWindow = (int) RecruitmentSetting::get('joining_risk_followup_days', 3);

        return $daysToJoin <= $followUpWindow ? 'yellow' : 'green';
    }

    /**
     * @return BelongsTo<CandidateApplication, $this>
     */
    public function candidateApplication(): BelongsTo
    {
        return $this->belongsTo(CandidateApplication::class);
    }

    /**
     * @return BelongsTo<Offer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /**
     * @return BelongsTo<RecruitmentRejectionReason, $this>
     */
    public function dropoutReason(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRejectionReason::class, 'dropout_reason_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    /**
     * @return HasMany<CandidateDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(CandidateDocument::class);
    }
}
