<?php

use App\Enums\CandidateStage;
use App\Enums\InterviewMode;
use App\Enums\InterviewSlotStatus;
use App\Enums\InterviewStatus;
use App\Enums\SchedulingChannel;
use App\Enums\SlotBookingStatus;
use App\Events\CandidateRescheduled;
use App\Events\InterviewScheduled;
use App\Events\InterviewSlotBooked;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\InterviewAvailabilitySlot;
use App\Models\InterviewSchedulingInvitation;
use App\Services\InterviewSchedulingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->scheduling = app(InterviewSchedulingService::class);
});

function schedulingInvitation(): InterviewSchedulingInvitation
{
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Shortlisted]);

    return app(InterviewSchedulingService::class)->invite($application);
}

test('slots are defined in a local timezone and stored in UTC', function (): void {
    Carbon::setTestNow('2026-09-25 09:00:00');
    $interviewer = Employee::factory()->create();

    $slots = $this->scheduling->createSlots($interviewer, [
        'starts_at' => '2026-10-01 10:00', 'timezone' => 'Asia/Kolkata', 'duration_minutes' => 30, 'count' => 3, 'mode' => InterviewMode::VideoCall->value,
    ]);

    expect($slots)->toHaveCount(3)
        ->and($slots->first()->starts_at->toDateTimeString())->toBe('2026-10-01 04:30:00')
        ->and($slots->last()->ends_at->toDateTimeString())->toBe('2026-10-01 06:00:00')
        ->and($slots->first()->localStart()->format('H:i'))->toBe('10:00')
        ->and($slots->first()->status)->toBe(InterviewSlotStatus::Available)
        ->and(AuditLog::query()->where('auditable_type', InterviewAvailabilitySlot::class)->where('action', 'created')->count())->toBe(3);
});

test('slot creation refuses overlaps, past times and unknown timezones', function (array $data, string $message): void {
    Carbon::setTestNow('2026-09-25 09:00:00');
    $interviewer = Employee::factory()->create();
    $this->scheduling->createSlots($interviewer, ['starts_at' => '2026-10-01 10:00', 'timezone' => 'UTC', 'duration_minutes' => 60, 'mode' => 'video_call']);

    expect(fn () => $this->scheduling->createSlots($interviewer, [...['timezone' => 'UTC', 'duration_minutes' => 60, 'mode' => 'video_call'], ...$data]))
        ->toThrow(DomainException::class, $message);
})->with([
    'overlap' => [['starts_at' => '2026-10-01 10:30'], 'overlapping'],
    'past' => [['starts_at' => '2026-09-01 10:00'], 'future'],
    'bad timezone' => [['starts_at' => '2026-10-02 10:00', 'timezone' => 'Mars/Olympus'], 'not a valid timezone'],
]);

test('booking a slot creates the interview through the interview service and fills the slot', function (): void {
    Event::fake([InterviewSlotBooked::class]);
    $invitation = schedulingInvitation();
    $slot = InterviewAvailabilitySlot::factory()->create();

    $booking = $this->scheduling->book($invitation, $slot, SchedulingChannel::SignedLink);

    expect($booking->status)->toBe(SlotBookingStatus::Booked)
        ->and($booking->interview->status)->toBe(InterviewStatus::Scheduled)
        ->and($booking->interview->interviewer_id)->toBe($slot->interviewer_id)
        ->and($booking->interview->scheduled_at->equalTo($slot->starts_at))->toBeTrue()
        ->and($invitation->candidateApplication->fresh()->current_stage)->toBe(CandidateStage::InterviewScheduled)
        ->and($slot->fresh()->status)->toBe(InterviewSlotStatus::Booked)
        ->and($invitation->fresh()->used_at)->not->toBeNull();
    Event::assertDispatched(InterviewSlotBooked::class);
});

test('a full slot cannot be double booked', function (): void {
    $slot = InterviewAvailabilitySlot::factory()->create();
    $this->scheduling->book(schedulingInvitation(), $slot, SchedulingChannel::SignedLink);

    $this->scheduling->book(schedulingInvitation(), $slot->fresh(), SchedulingChannel::SignedLink);
})->throws(DomainException::class, 'just been taken');

test('a slot with capacity takes several candidates then closes', function (): void {
    $slot = InterviewAvailabilitySlot::factory()->create(['capacity' => 2]);

    $this->scheduling->book(schedulingInvitation(), $slot, SchedulingChannel::SignedLink);
    expect($slot->fresh()->status)->toBe(InterviewSlotStatus::Available);

    $this->scheduling->book(schedulingInvitation(), $slot, SchedulingChannel::SignedLink);
    expect($slot->fresh()->status)->toBe(InterviewSlotStatus::Booked)->and($slot->fresh()->booked_count)->toBe(2);
});

test('expired, cancelled and too-soon slots cannot be booked', function (callable $makeSlot, string $message): void {
    $invitation = schedulingInvitation();
    $slot = $makeSlot();

    expect(fn () => $this->scheduling->book($invitation, $slot, SchedulingChannel::SignedLink))->toThrow(DomainException::class, $message);
})->with([
    'expired status' => [fn () => InterviewAvailabilitySlot::factory()->status(InterviewSlotStatus::Expired)->create(), 'no longer available'],
    'cancelled' => [fn () => InterviewAvailabilitySlot::factory()->status(InterviewSlotStatus::Cancelled)->create(), 'cancelled'],
    'inside the minimum lead time' => [fn () => InterviewAvailabilitySlot::factory()->startingAt(now()->addHour())->create(), 'no longer available'],
    'past its booking deadline' => [fn () => InterviewAvailabilitySlot::factory()->create(['bookable_until' => now()->subMinute()]), 'no longer available'],
]);

test('a slot for another position cannot be booked', function (): void {
    $slot = InterviewAvailabilitySlot::factory()->create(['requisition_id' => CandidateApplication::factory()->create()->requisition_id]);

    $this->scheduling->book(schedulingInvitation(), $slot, SchedulingChannel::SignedLink);
})->throws(DomainException::class, 'not offered for this position');

test('an application books at most one active slot', function (): void {
    $invitation = schedulingInvitation();
    $this->scheduling->book($invitation, InterviewAvailabilitySlot::factory()->create(), SchedulingChannel::SignedLink);
    $second = $this->scheduling->invite($invitation->candidateApplication);

    $this->scheduling->book($second, InterviewAvailabilitySlot::factory()->startingAt(now()->addDays(3))->create(), SchedulingChannel::SignedLink);
})->throws(DomainException::class, 'already booked');

test('a used or expired invitation cannot book', function (): void {
    $invitation = schedulingInvitation();
    $invitation->forceFill(['expires_at' => now()->subMinute()])->save();

    $this->scheduling->book($invitation, InterviewAvailabilitySlot::factory()->create(), SchedulingChannel::SignedLink);
})->throws(DomainException::class, 'expired');

test('rescheduling moves the booking and interview to the new slot and frees the old one', function (): void {
    Event::fake([CandidateRescheduled::class]);
    $old = InterviewAvailabilitySlot::factory()->create();
    $new = InterviewAvailabilitySlot::factory()->startingAt(now()->addDays(4)->setTime(15, 0))->create();
    $booking = $this->scheduling->book(schedulingInvitation(), $old, SchedulingChannel::SignedLink);

    $moved = $this->scheduling->reschedule($booking, $new, reason: 'Clashes with exam');

    expect($moved->rescheduled_from_id)->toBe($booking->id)
        ->and($booking->fresh()->status)->toBe(SlotBookingStatus::Rescheduled)
        ->and($old->fresh()->status)->toBe(InterviewSlotStatus::Available)
        ->and($old->fresh()->booked_count)->toBe(0)
        ->and($new->fresh()->status)->toBe(InterviewSlotStatus::Booked)
        ->and($moved->interview->status)->toBe(InterviewStatus::Rescheduled)
        ->and($moved->interview->interviewer_id)->toBe($new->interviewer_id)
        ->and($moved->interview->scheduled_at->equalTo($new->starts_at))->toBeTrue();
    Event::assertDispatched(CandidateRescheduled::class);
});

test('cancelling a booking frees the slot and cancels the interview', function (): void {
    $slot = InterviewAvailabilitySlot::factory()->create();
    $booking = $this->scheduling->book(schedulingInvitation(), $slot, SchedulingChannel::SignedLink);

    $this->scheduling->cancelBooking($booking, 'No longer interested');

    expect($booking->fresh()->status)->toBe(SlotBookingStatus::Cancelled)
        ->and($booking->interview->fresh()->status)->toBe(InterviewStatus::Cancelled)
        ->and($slot->fresh()->status)->toBe(InterviewSlotStatus::Available);
});

test('a slot with an active booking cannot be withdrawn', function (): void {
    $slot = InterviewAvailabilitySlot::factory()->create();
    $this->scheduling->book(schedulingInvitation(), $slot, SchedulingChannel::SignedLink);

    $this->scheduling->cancelSlot($slot->fresh(), 'Interviewer on leave');
})->throws(DomainException::class, 'active bookings');

test('lapsed open slots are expired by the scheduled command', function (): void {
    $lapsed = InterviewAvailabilitySlot::factory()->startingAt(now()->addMinutes(30))->create();
    $future = InterviewAvailabilitySlot::factory()->create();

    $this->artisan('interview-slots:expire')->expectsOutputToContain('Expired 1 interview slot(s)')->assertSuccessful();

    expect($lapsed->fresh()->status)->toBe(InterviewSlotStatus::Expired)
        ->and($future->fresh()->status)->toBe(InterviewSlotStatus::Available);
});

test('available slots for an invitation respect the position and invited interviewer', function (): void {
    $invitation = schedulingInvitation();
    $interviewer = Employee::factory()->create();
    $invitation->forceFill(['interviewer_id' => $interviewer->id])->save();
    $mine = InterviewAvailabilitySlot::factory()->create(['interviewer_id' => $interviewer->id]);
    InterviewAvailabilitySlot::factory()->create();
    InterviewAvailabilitySlot::factory()->create(['interviewer_id' => $interviewer->id, 'requisition_id' => CandidateApplication::factory()->create()->requisition_id]);

    expect($this->scheduling->availableSlotsFor($invitation->fresh())->pluck('id')->all())->toBe([$mine->id]);
});

test('a booking raises InterviewScheduled, which drives external calendar sync after commit', function (): void {
    Event::fake([InterviewScheduled::class]);

    $booking = app(InterviewSchedulingService::class)->book(schedulingInvitation(), InterviewAvailabilitySlot::factory()->create(), SchedulingChannel::SignedLink);

    Event::assertDispatched(InterviewScheduled::class, fn (InterviewScheduled $e) => $e->interview->is($booking->interview));
});
