<?php

namespace App\Models;

use App\Enums\TimelineEventType;
use App\Enums\TimelineSource;
use App\Enums\TimelineVisibility;
use App\Models\Concerns\BelongsToTenant;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One append-only entry of the unified candidate timeline (Phase 4). Written only through
 * CandidateTimelineService::record(); immutable once written, like the other history tables.
 * `actor` is whoever acted (an Employee, or a CandidatePortalAccount for portal activity);
 * `subject` is the record the event is about (e.g. an EmployeeReferral or TalentPool).
 */
#[Fillable([
    'candidate_id',
    'candidate_application_id',
    'requisition_id',
    'interview_id',
    'offer_id',
    'candidate_joining_id',
    'event_type',
    'source',
    'visibility',
    'actor_type',
    'actor_id',
    'subject_type',
    'subject_id',
    'title',
    'description',
    'metadata',
    'occurred_at',
])]
class CandidateTimelineEvent extends Model
{
    use BelongsToTenant;

    public const ?string UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException('Timeline events are append-only.'));
        static::deleting(fn () => throw new DomainException('Timeline events are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'event_type' => TimelineEventType::class,
            'source' => TimelineSource::class,
            'visibility' => TimelineVisibility::class,
            'metadata' => 'array',
            'occurred_at' => 'datetime',
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
     * @return BelongsTo<Interview, $this>
     */
    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }

    /**
     * @return BelongsTo<Offer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /**
     * @return BelongsTo<CandidateJoining, $this>
     */
    public function candidateJoining(): BelongsTo
    {
        return $this->belongsTo(CandidateJoining::class);
    }

    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function actorName(): ?string
    {
        return match (true) {
            $this->actor instanceof Employee => $this->actor->fullName(),
            $this->actor !== null && method_exists($this->actor, 'displayName') => $this->actor->displayName(),
            default => null,
        };
    }
}
