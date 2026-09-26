<?php

namespace App\Models;

use App\Enums\SchedulingChannel;
use App\Enums\SlotBookingStatus;
use App\Models\Concerns\Auditable;
use Database\Factories\InterviewSlotBookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A candidate's booking of an availability slot, linked to the Interview it created. Written only
 * by InterviewSchedulingService; audited via Auditable (booked, cancelled, rescheduled).
 */
#[Fillable([
    'slot_id',
    'candidate_application_id',
    'candidate_id',
    'interview_id',
    'invitation_id',
    'rescheduled_from_id',
    'channel',
    'booked_at',
    'candidate_note',
])]
class InterviewSlotBooking extends Model
{
    /** @use HasFactory<InterviewSlotBookingFactory> */
    use Auditable, HasFactory, HasUlids;

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return [
            'status' => SlotBookingStatus::class,
            'channel' => SchedulingChannel::class,
            'booked_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<InterviewAvailabilitySlot, $this>
     */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(InterviewAvailabilitySlot::class, 'slot_id');
    }

    /**
     * @return BelongsTo<CandidateApplication, $this>
     */
    public function candidateApplication(): BelongsTo
    {
        return $this->belongsTo(CandidateApplication::class);
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /**
     * @return BelongsTo<Interview, $this>
     */
    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }

    /**
     * @return BelongsTo<InterviewSlotBooking, $this>
     */
    public function rescheduledFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rescheduled_from_id');
    }

    public function isActive(): bool
    {
        return $this->status === SlotBookingStatus::Booked;
    }
}
