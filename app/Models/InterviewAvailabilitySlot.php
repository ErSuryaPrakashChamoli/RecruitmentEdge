<?php

namespace App\Models;

use App\Enums\InterviewMode;
use App\Enums\InterviewSlotStatus;
use App\Models\Concerns\Auditable;
use Carbon\CarbonInterface;
use Database\Factories\InterviewAvailabilitySlotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One block of interviewer availability candidates can self-book (Phase 4). `starts_at`/`ends_at`
 * are UTC; `timezone` is the zone it was defined in and is shown in. Only
 * InterviewSchedulingService changes status/booked_count; creation and cancellation are audited.
 * `public_id` (ULID) is the only identifier ever exposed to candidates.
 */
#[Fillable([
    'interviewer_id',
    'requisition_id',
    'round_name',
    'starts_at',
    'ends_at',
    'timezone',
    'capacity',
    'mode',
    'location',
    'meeting_link',
    'bookable_until',
    'created_by',
])]
class InterviewAvailabilitySlot extends Model
{
    /** @use HasFactory<InterviewAvailabilitySlotFactory> */
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
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'bookable_until' => 'datetime',
            'cancelled_at' => 'datetime',
            'capacity' => 'integer',
            'booked_count' => 'integer',
            'status' => InterviewSlotStatus::class,
            'mode' => InterviewMode::class,
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'interviewer_id');
    }

    /**
     * @return BelongsTo<RecruitmentRequisition, $this>
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRequisition::class, 'requisition_id');
    }

    /**
     * @return HasMany<InterviewSlotBooking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(InterviewSlotBooking::class, 'slot_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    public function remainingCapacity(): int
    {
        return max(0, $this->capacity - $this->booked_count);
    }

    /**
     * The minimum notice before a slot starts for a candidate to still book it
     * (recruitment setting `self_scheduling_min_lead_hours`, default 2).
     */
    public static function minimumLeadHours(): int
    {
        return (int) RecruitmentSetting::get('self_scheduling_min_lead_hours', 2);
    }

    public function bookingClosesAt(): CarbonInterface
    {
        $leadCutoff = $this->starts_at->copy()->subHours(self::minimumLeadHours());

        return $this->bookable_until !== null && $this->bookable_until->lt($leadCutoff) ? $this->bookable_until : $leadCutoff;
    }

    /**
     * Why this slot can't be booked right now, or null when it can. Evaluated under the booking
     * row lock by InterviewSchedulingService.
     */
    public function unbookableReason(): ?string
    {
        return match (true) {
            $this->status === InterviewSlotStatus::Cancelled => 'This slot has been cancelled.',
            $this->status === InterviewSlotStatus::Expired, $this->bookingClosesAt()->isPast() => 'This slot is no longer available to book.',
            $this->status !== InterviewSlotStatus::Available || $this->remainingCapacity() === 0 => 'This slot has just been taken. Please choose another.',
            default => null,
        };
    }

    /**
     * Slots a candidate could book now (the row-locked re-check in the service is authoritative).
     *
     * @param  Builder<InterviewAvailabilitySlot>  $query
     */
    #[Scope]
    protected function bookable(Builder $query): void
    {
        $query->where('status', InterviewSlotStatus::Available)
            ->whereColumn('booked_count', '<', 'capacity')
            ->where('starts_at', '>', now()->addHours(self::minimumLeadHours()))
            ->where(fn (Builder $q) => $q->whereNull('bookable_until')->orWhere('bookable_until', '>', now()));
    }

    public function localStart(): CarbonInterface
    {
        return $this->starts_at->copy()->setTimezone($this->timezone);
    }

    public function localEnd(): CarbonInterface
    {
        return $this->ends_at->copy()->setTimezone($this->timezone);
    }

    public function displayWindow(): string
    {
        return $this->localStart()->format('D, d M Y · h:i A').' – '.$this->localEnd()->format('h:i A').' ('.$this->timezone.')';
    }
}
