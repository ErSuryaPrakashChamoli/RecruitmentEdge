<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\Priority;
use Database\Factories\CandidateApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'application_code',
    'candidate_id',
    'requisition_id',
    'recruiter_id',
    'current_stage',
    'application_date',
    'priority',
    'last_activity_at',
    'next_followup_at',
    'status',
    'rejection_reason_id',
    'dropout_reason_id',
    'remarks',
])]
class CandidateApplication extends Model
{
    /** @use HasFactory<CandidateApplicationFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'current_stage' => CandidateStage::class,
            'priority' => Priority::class,
            'status' => ApplicationStatus::class,
            'application_date' => 'date',
            'last_activity_at' => 'datetime',
            'next_followup_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
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
    public function recruiter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'recruiter_id');
    }

    /**
     * @return BelongsTo<RecruitmentRejectionReason, $this>
     */
    public function rejectionReason(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRejectionReason::class, 'rejection_reason_id');
    }

    /**
     * @return BelongsTo<RecruitmentRejectionReason, $this>
     */
    public function dropoutReason(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRejectionReason::class, 'dropout_reason_id');
    }

    /**
     * @return HasMany<CandidateStageHistory, $this>
     */
    public function stageHistory(): HasMany
    {
        return $this->hasMany(CandidateStageHistory::class)->latest('created_at');
    }

    /**
     * @return HasMany<Interview, $this>
     */
    public function interviews(): HasMany
    {
        return $this->hasMany(Interview::class);
    }

    /**
     * @return HasMany<Offer, $this>
     */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    /**
     * @return HasOne<CandidateJoining, $this>
     */
    public function joining(): HasOne
    {
        return $this->hasOne(CandidateJoining::class);
    }
}
