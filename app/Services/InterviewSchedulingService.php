<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\InterviewMode;
use App\Enums\InterviewSlotStatus;
use App\Enums\SchedulingChannel;
use App\Enums\SlotBookingStatus;
use App\Enums\TimelineEventType;
use App\Enums\TimelineSource;
use App\Enums\TimelineVisibility;
use App\Events\CandidateRescheduled;
use App\Events\InterviewSlotBooked;
use App\Events\InterviewSlotCancelled;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\InterviewAvailabilitySlot;
use App\Models\InterviewSchedulingInvitation;
use App\Models\InterviewSlotBooking;
use App\Models\RecruitmentSetting;
use App\Services\Integrations\Calendar\CalendarSyncService;
use Carbon\CarbonInterface;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Candidate self-scheduling (Phase 4). Interviewers publish availability slots; a candidate with
 * an invitation (signed link or portal) books one, and the booking creates the Interview through
 * InterviewService::schedule() — the single interview-creation path — so stage sync and
 * notifications are unchanged.
 *
 * Integrity: bookings take a row lock on the slot and re-check it (status, expiry, lead time,
 * capacity), so two candidates can never double-book the last seat; an application holds at most
 * one active booking (use reschedule to move it). Times are stored in UTC and shown in the slot's
 * own timezone.
 *
 * External calendars (Phase 5): bookings create/reschedule/cancel the interview through
 * InterviewService, whose events drive CalendarSyncService (queued, retried) — this service never
 * calls a calendar provider itself, so a provider failure can never undo a booking.
 */
class InterviewSchedulingService
{
    public function __construct(
        private readonly InterviewService $interviews,
        private readonly CandidateTimelineService $timeline,
        private readonly CalendarSyncService $calendarSync,
    ) {}

    /**
     * Creates one slot, or `count` consecutive slots of `duration_minutes` starting at `starts_at`
     * (a local date-time in `timezone`). Refuses past times, bad timezones and overlaps with the
     * interviewer's other live slots.
     *
     * @param  array{starts_at: string|CarbonInterface, timezone: string, duration_minutes: int|string, count?: int|string|null, capacity?: int|string|null, mode: InterviewMode|string, location?: string|null, meeting_link?: string|null, requisition_id?: int|string|null, round_name?: string|null, bookable_until?: string|CarbonInterface|null}  $data
     * @return Collection<int, InterviewAvailabilitySlot>
     */
    public function createSlots(Employee $interviewer, array $data, ?Employee $actor = null): Collection
    {
        $timezone = (string) $data['timezone'];

        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new DomainException("\"{$timezone}\" is not a valid timezone.");
        }

        $duration = (int) $data['duration_minutes'];
        $count = max(1, (int) ($data['count'] ?? 1));
        $capacity = max(1, (int) ($data['capacity'] ?? 1));

        if ($duration < 5 || $duration > 480) {
            throw new DomainException('A slot must be between 5 minutes and 8 hours long.');
        }

        if ($count > 50) {
            throw new DomainException('Create at most 50 slots at a time.');
        }

        $firstStart = Carbon::parse($data['starts_at'], $timezone)->utc();

        if ($firstStart->isPast()) {
            throw new DomainException('Slots must start in the future.');
        }

        $bookableUntil = filled($data['bookable_until'] ?? null) ? Carbon::parse($data['bookable_until'], $timezone)->utc() : null;
        $mode = $data['mode'] instanceof InterviewMode ? $data['mode'] : InterviewMode::from((string) $data['mode']);

        // Phase 5: an interviewer with a connected calendar can't publish slots over busy time.
        $busy = $this->calendarSync->busyTimesFor($interviewer, $firstStart, $firstStart->copy()->addMinutes($duration * $count));

        return DB::transaction(function () use ($interviewer, $data, $timezone, $duration, $count, $capacity, $firstStart, $bookableUntil, $mode, $actor, $busy): Collection {
            return collect(range(0, $count - 1))->map(function (int $i) use ($interviewer, $data, $timezone, $duration, $capacity, $firstStart, $bookableUntil, $mode, $actor, $busy): InterviewAvailabilitySlot {
                $start = $firstStart->copy()->addMinutes($duration * $i);
                $end = $start->copy()->addMinutes($duration);

                $overlap = InterviewAvailabilitySlot::query()
                    ->where('interviewer_id', $interviewer->id)
                    ->whereNotIn('status', [InterviewSlotStatus::Cancelled, InterviewSlotStatus::Expired])
                    ->where('starts_at', '<', $end)
                    ->where('ends_at', '>', $start)
                    ->lockForUpdate()
                    ->exists();

                if ($overlap) {
                    throw new DomainException("{$interviewer->fullName()} already has a slot overlapping ".$start->copy()->setTimezone($timezone)->format('d M Y h:i A').'.');
                }

                foreach ($busy as $interval) {
                    if ($interval['start']->lt($end) && $interval['end']->gt($start)) {
                        throw new DomainException("{$interviewer->fullName()}'s calendar is busy at ".$start->copy()->setTimezone($timezone)->format('d M Y h:i A').'.');
                    }
                }

                $slot = new InterviewAvailabilitySlot([
                    'interviewer_id' => $interviewer->id,
                    'requisition_id' => filled($data['requisition_id'] ?? null) ? (int) $data['requisition_id'] : null,
                    'round_name' => $data['round_name'] ?? null,
                    'starts_at' => $start,
                    'ends_at' => $end,
                    'timezone' => $timezone,
                    'capacity' => $capacity,
                    'mode' => $mode,
                    'location' => $data['location'] ?? null,
                    'meeting_link' => $data['meeting_link'] ?? null,
                    'bookable_until' => $bookableUntil,
                    'created_by' => $actor?->id,
                ]);
                $slot->forceFill(['status' => InterviewSlotStatus::Available, 'booked_count' => 0])->save();

                return $slot;
            });
        });
    }

    /**
     * Invites an application's candidate to self-schedule; returns the invitation. The signed link
     * comes from signedLinkFor().
     */
    public function invite(CandidateApplication $application, ?Employee $actor = null, ?Employee $interviewer = null, ?string $roundName = null, ?int $validDays = null): InterviewSchedulingInvitation
    {
        if ($application->status !== ApplicationStatus::Active) {
            throw new DomainException('Only an active application can be invited to self-schedule.');
        }

        $days = $validDays ?? (int) RecruitmentSetting::get('self_scheduling_invitation_days', 7);

        return DB::transaction(function () use ($application, $actor, $interviewer, $roundName, $days): InterviewSchedulingInvitation {
            $invitation = InterviewSchedulingInvitation::query()->create([
                'candidate_application_id' => $application->id,
                'interviewer_id' => $interviewer?->id,
                'round_name' => $roundName,
                'expires_at' => now()->addDays(max(1, $days)),
                'created_by' => $actor?->id,
            ]);

            $this->timeline->record(
                $application->candidate_id,
                TimelineEventType::InterviewScheduled,
                'Invited to choose an interview slot',
                'The invitation link is valid until '.$invitation->expires_at->format('d M Y').'.',
                TimelineSource::Recruiter,
                TimelineVisibility::Candidate,
                $actor,
                ['application' => $application, 'subject' => $invitation],
            );

            return $invitation;
        });
    }

    public function signedLinkFor(InterviewSchedulingInvitation $invitation): string
    {
        return URL::temporarySignedRoute('portal.schedule.show', $invitation->expires_at, ['invitation' => $invitation->public_id]);
    }

    /**
     * Slots the invitation's candidate may pick from now: bookable, for the application's
     * requisition (or any requisition), and the invited interviewer if one was set.
     *
     * @return Collection<int, InterviewAvailabilitySlot>
     */
    public function availableSlotsFor(InterviewSchedulingInvitation $invitation, ?InterviewAvailabilitySlot $except = null): Collection
    {
        $application = $invitation->candidateApplication;

        return InterviewAvailabilitySlot::query()
            ->bookable()
            ->where(fn (Builder $q) => $q->whereNull('requisition_id')->orWhere('requisition_id', $application->requisition_id))
            ->when($invitation->interviewer_id !== null, fn (Builder $q) => $q->where('interviewer_id', $invitation->interviewer_id))
            ->when($except !== null, fn (Builder $q) => $q->whereKeyNot($except->id))
            ->with('interviewer:id,first_name,last_name')
            ->orderBy('starts_at')
            ->limit(100)
            ->get();
    }

    /**
     * Books $slot for the invitation's application and creates the interview.
     */
    public function book(InterviewSchedulingInvitation $invitation, InterviewAvailabilitySlot $slot, SchedulingChannel $channel, ?Model $actor = null, ?string $note = null): InterviewSlotBooking
    {
        if (! $invitation->isOpen()) {
            throw new DomainException('This scheduling link has expired or was already used.');
        }

        $application = $invitation->candidateApplication;

        $booking = DB::transaction(function () use ($invitation, $slot, $channel, $actor, $note, $application): InterviewSlotBooking {
            $locked = $this->lockBookableSlot($slot, $application);

            if ($this->activeBookingFor($application) !== null) {
                throw new DomainException('An interview is already booked for this application. Reschedule it instead.');
            }

            $interview = $this->interviews->schedule($application, [
                'interviewer_id' => $locked->interviewer_id,
                'scheduled_at' => $locked->starts_at,
                'mode' => $locked->mode,
                'location' => $locked->location,
                'meeting_link' => $locked->meeting_link,
                'round_name' => $invitation->round_name ?? $locked->round_name,
                'remarks' => 'Self-scheduled by the candidate',
            ], $actor instanceof Employee ? $actor : null);

            $booking = $this->createBooking($locked, $application, $channel, $interview->id, $invitation->id, null, $note);

            $invitation->forceFill(['used_at' => now()])->save();

            $this->timeline->record(
                $application->candidate_id,
                TimelineEventType::InterviewScheduled,
                'Interview booked for '.$locked->displayWindow(),
                null,
                $channel === SchedulingChannel::Recruiter ? TimelineSource::Recruiter : TimelineSource::CandidatePortal,
                TimelineVisibility::Candidate,
                $actor,
                ['interview' => $interview, 'subject' => $booking],
                ['slot' => $locked->public_id, 'channel' => $channel->value],
            );

            return $booking;
        });

        InterviewSlotBooked::dispatch($booking);

        return $booking;
    }

    /**
     * Moves an active booking to another slot: the old seat is released, a new booking (pointing
     * at the old one) is made, and the interview is rescheduled — all in one transaction.
     */
    public function reschedule(InterviewSlotBooking $booking, InterviewAvailabilitySlot $newSlot, ?Model $actor = null, ?string $reason = null): InterviewSlotBooking
    {
        if (! $booking->isActive()) {
            throw new DomainException('Only an active booking can be rescheduled.');
        }

        if ($newSlot->is($booking->slot)) {
            throw new DomainException('Choose a different slot.');
        }

        $application = $booking->candidateApplication;

        $new = DB::transaction(function () use ($booking, $newSlot, $actor, $reason, $application): InterviewSlotBooking {
            $locked = $this->lockBookableSlot($newSlot, $application);
            $this->releaseSeat($booking->slot);

            $booking->forceFill(['status' => SlotBookingStatus::Rescheduled, 'cancelled_at' => now(), 'cancellation_reason' => $reason])->save();

            if ($booking->interview !== null) {
                $this->interviews->reschedule($booking->interview, $locked->starts_at, $reason ?? 'Candidate chose a new slot', $actor instanceof Employee ? $actor : null, [
                    'interviewer_id' => $locked->interviewer_id,
                    'mode' => $locked->mode,
                    'location' => $locked->location,
                    'meeting_link' => $locked->meeting_link,
                ]);
            }

            $new = $this->createBooking($locked, $application, $booking->channel, $booking->interview_id, $booking->invitation_id, $booking->id, $booking->candidate_note);

            $this->timeline->record(
                $application->candidate_id,
                TimelineEventType::InterviewRescheduled,
                'Interview rescheduled to '.$locked->displayWindow(),
                $reason,
                $actor instanceof Employee ? TimelineSource::Recruiter : TimelineSource::CandidatePortal,
                TimelineVisibility::Candidate,
                $actor,
                ['interview' => $booking->interview, 'subject' => $new],
            );

            return $new;
        });

        CandidateRescheduled::dispatch($new, $booking);

        return $new;
    }

    public function cancelBooking(InterviewSlotBooking $booking, string $reason, ?Model $actor = null): InterviewSlotBooking
    {
        if (! $booking->isActive()) {
            throw new DomainException('This booking is not active.');
        }

        if (blank($reason)) {
            throw new DomainException('A reason is required to cancel a booking.');
        }

        DB::transaction(function () use ($booking, $reason, $actor): void {
            $this->releaseSeat($booking->slot);

            $booking->forceFill(['status' => SlotBookingStatus::Cancelled, 'cancelled_at' => now(), 'cancellation_reason' => $reason])->save();

            if ($booking->interview !== null && ! $booking->interview->status->isTerminal()) {
                $this->interviews->cancel($booking->interview, $reason, $actor instanceof Employee ? $actor : null);
            }

            $this->timeline->record(
                $booking->candidate_id,
                TimelineEventType::InterviewCancelled,
                'Interview booking cancelled',
                $reason,
                $actor instanceof Employee ? TimelineSource::Recruiter : TimelineSource::CandidatePortal,
                TimelineVisibility::Candidate,
                $actor,
                ['interview' => $booking->interview, 'subject' => $booking],
            );
        });

        InterviewSlotCancelled::dispatch($booking->slot, $booking);

        return $booking;
    }

    /**
     * Withdraws an unbooked slot. A slot with active bookings must have them cancelled or
     * rescheduled first — withdrawing it must never silently strand a candidate.
     */
    public function cancelSlot(InterviewAvailabilitySlot $slot, string $reason, ?Employee $actor = null): InterviewAvailabilitySlot
    {
        if (in_array($slot->status, [InterviewSlotStatus::Cancelled, InterviewSlotStatus::Expired], true)) {
            throw new DomainException("This slot is already {$slot->status->label()}.");
        }

        if ($slot->bookings()->where('status', SlotBookingStatus::Booked)->exists()) {
            throw new DomainException('This slot has active bookings. Cancel or reschedule them first.');
        }

        $slot->forceFill([
            'status' => InterviewSlotStatus::Cancelled,
            'cancelled_by' => $actor?->id,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ])->save();

        InterviewSlotCancelled::dispatch($slot);

        return $slot;
    }

    /**
     * Marks open slots whose booking window has closed as Expired (scheduled hourly via
     * interview-slots:expire). Returns how many were expired.
     */
    public function expireLapsedSlots(): int
    {
        $count = 0;

        InterviewAvailabilitySlot::query()
            ->where('status', InterviewSlotStatus::Available)
            ->where(fn (Builder $q) => $q->where('starts_at', '<=', now()->addHours(InterviewAvailabilitySlot::minimumLeadHours()))
                ->orWhere('bookable_until', '<=', now()))
            ->chunkById(200, function (Collection $slots) use (&$count): void {
                foreach ($slots as $slot) {
                    $slot->forceFill(['status' => InterviewSlotStatus::Expired])->save();
                    $count++;
                }
            });

        return $count;
    }

    public function activeBookingFor(CandidateApplication $application): ?InterviewSlotBooking
    {
        return InterviewSlotBooking::query()
            ->where('candidate_application_id', $application->id)
            ->where('status', SlotBookingStatus::Booked)
            ->first();
    }

    private function lockBookableSlot(InterviewAvailabilitySlot $slot, CandidateApplication $application): InterviewAvailabilitySlot
    {
        if ($application->status !== ApplicationStatus::Active) {
            throw new DomainException('This application is no longer active.');
        }

        /** @var InterviewAvailabilitySlot $locked */
        $locked = InterviewAvailabilitySlot::query()->whereKey($slot->id)->lockForUpdate()->firstOrFail();

        if ($locked->requisition_id !== null && $locked->requisition_id !== $application->requisition_id) {
            throw new DomainException('This slot is not offered for this position.');
        }

        if (($reason = $locked->unbookableReason()) !== null) {
            throw new DomainException($reason);
        }

        $locked->forceFill(['booked_count' => $locked->booked_count + 1])->save();

        if ($locked->remainingCapacity() === 0) {
            $locked->forceFill(['status' => InterviewSlotStatus::Booked])->save();
        }

        return $locked;
    }

    private function releaseSeat(InterviewAvailabilitySlot $slot): void
    {
        /** @var InterviewAvailabilitySlot $locked */
        $locked = InterviewAvailabilitySlot::query()->whereKey($slot->id)->lockForUpdate()->firstOrFail();

        $locked->forceFill([
            'booked_count' => max(0, $locked->booked_count - 1),
            'status' => $locked->status === InterviewSlotStatus::Booked ? InterviewSlotStatus::Available : $locked->status,
        ])->save();
    }

    private function createBooking(
        InterviewAvailabilitySlot $slot,
        CandidateApplication $application,
        SchedulingChannel $channel,
        ?int $interviewId,
        ?int $invitationId,
        ?int $rescheduledFromId,
        ?string $note,
    ): InterviewSlotBooking {
        $booking = new InterviewSlotBooking([
            'slot_id' => $slot->id,
            'candidate_application_id' => $application->id,
            'candidate_id' => $application->candidate_id,
            'interview_id' => $interviewId,
            'invitation_id' => $invitationId,
            'rescheduled_from_id' => $rescheduledFromId,
            'channel' => $channel,
            'booked_at' => now(),
            'candidate_note' => $note,
        ]);
        $booking->forceFill(['status' => SlotBookingStatus::Booked])->save();

        return $booking;
    }
}
