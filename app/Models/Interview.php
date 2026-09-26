<?php

namespace App\Models;

use App\Enums\InterviewMode;
use App\Enums\InterviewResult;
use App\Enums\InterviewStatus;
use App\Enums\MeetingProvider;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\GuardsLifecycleAttributes;
use Database\Factories\InterviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'candidate_application_id',
    'round_number',
    'round_name',
    'interviewer_id',
    'scheduled_at',
    'mode',
    'location',
    'meeting_link',
    'meeting_provider',
    'external_meeting_id',
    'status',
    'result',
    'rejection_reason_id',
    'remarks',
    'created_by',
])]
class Interview extends Model
{
    use Auditable, GuardsLifecycleAttributes;

    /** @use HasFactory<InterviewFactory> */
    use HasFactory;

    /**
     * Phase 8.3: status, outcome, time, interviewer and application change only through
     * InterviewService (schedule / reschedule / confirm / hold / cancel / no-show / complete).
     *
     * @return array<int, string>
     */
    public function lifecycleAttributes(): array
    {
        return ['status', 'result', 'rejection_reason_id', 'scheduled_at', 'interviewer_id', 'candidate_application_id', 'round_number'];
    }

    public function lifecycleOwner(): string
    {
        return 'InterviewService';
    }

    protected function casts(): array
    {
        return [
            'mode' => InterviewMode::class,
            'status' => InterviewStatus::class,
            'result' => InterviewResult::class,
            'scheduled_at' => 'datetime',
            'meeting_provider' => MeetingProvider::class,
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
    public function interviewer(): BelongsTo
    {
        // Phase 8.4: a deleted employee's records keep their attribution (and stay visible to the
        // managers above them) — historical ownership is never silently dropped.
        return $this->belongsTo(Employee::class, 'interviewer_id')->withTrashed();
    }

    /**
     * @return BelongsTo<RecruitmentRejectionReason, $this>
     */
    public function rejectionReason(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRejectionReason::class, 'rejection_reason_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    /**
     * @return HasMany<InterviewCalendarEvent, $this>
     */
    public function calendarEvents(): HasMany
    {
        return $this->hasMany(InterviewCalendarEvent::class);
    }

    /**
     * @return HasMany<InterviewFeedback, $this>
     */
    /**
     * The current feedback — corrected entries replace their original here, while the originals
     * stay in allFeedback() (Phase 8.3).
     *
     * @return HasMany<InterviewFeedback, $this>
     */
    public function feedback(): HasMany
    {
        return $this->hasMany(InterviewFeedback::class)->where('is_current', true);
    }

    /**
     * Every feedback version, including superseded originals.
     *
     * @return HasMany<InterviewFeedback, $this>
     */
    public function allFeedback(): HasMany
    {
        return $this->hasMany(InterviewFeedback::class);
    }
}
