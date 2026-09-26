<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\Priority;
use App\Models\Concerns\GuardsLifecycleAttributes;
use App\Services\PipelineTemplateService;
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
    'origin_channel',
    'job_posting_id',
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
    use GuardsLifecycleAttributes, HasFactory, SoftDeletes;

    /**
     * Phase 8.3: stage and status change only through StageTransitionService; the requisition,
     * candidate and recruiter only through ApplicationAssignmentService.
     *
     * @return array<int, string>
     */
    public function lifecycleAttributes(): array
    {
        return ['current_stage', 'pipeline_stage_id', 'status', 'rejection_reason_id', 'dropout_reason_id', 'requisition_id', 'candidate_id', 'recruiter_id'];
    }

    public function lifecycleOwner(): string
    {
        return 'StageTransitionService / ApplicationAssignmentService';
    }

    /**
     * A new application on a requisition with a configured pipeline starts on the configured
     * stage matching its initial canonical stage — initial placement, not a transition, so no
     * history row (moves after creation go through StageTransitionService only).
     */
    protected static function booted(): void
    {
        static::creating(function (CandidateApplication $application): void {
            if ($application->pipeline_stage_id !== null || $application->requisition_id === null) {
                return;
            }

            $application->pipeline_stage_id = app(PipelineTemplateService::class)
                ->initialStageFor((int) $application->requisition_id, $application->current_stage ?? CandidateStage::Sourced)?->id;
        });
    }

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
     * The configured pipeline stage (from the requisition's snapshot) the application is at.
     * Null for legacy applications on requisitions without a pipeline; `current_stage` (the
     * canonical milestone) is always set and is what analytics read. Only StageTransitionService
     * writes it.
     *
     * @return BelongsTo<RequisitionPipelineStage, $this>
     */
    public function pipelineStage(): BelongsTo
    {
        return $this->belongsTo(RequisitionPipelineStage::class, 'pipeline_stage_id');
    }

    /**
     * The job posting an online application came through (Phase 5 distribution attribution).
     *
     * @return BelongsTo<JobPosting, $this>
     */
    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }

    /**
     * @return BelongsTo<RecruitmentCampaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(RecruitmentCampaign::class, 'campaign_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function recruiter(): BelongsTo
    {
        // Phase 8.4: a deleted employee's records keep their attribution (and stay visible to the
        // managers above them) — historical ownership is never silently dropped.
        return $this->belongsTo(Employee::class, 'recruiter_id')->withTrashed();
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

    /**
     * @return HasMany<RecruitmentDailyActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(RecruitmentDailyActivity::class)->latest('activity_datetime');
    }

    /**
     * @return HasMany<CandidateTimelineEvent, $this>
     */
    public function timelineEvents(): HasMany
    {
        return $this->hasMany(CandidateTimelineEvent::class)->latest('occurred_at');
    }

    /**
     * @return HasMany<RecruitmentFollowup, $this>
     */
    public function followups(): HasMany
    {
        return $this->hasMany(RecruitmentFollowup::class)->latest('followup_date');
    }
}
