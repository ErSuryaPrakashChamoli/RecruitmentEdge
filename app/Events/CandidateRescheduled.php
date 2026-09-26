<?php

namespace App\Events;

use App\Models\InterviewSlotBooking;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A booked interview moved to a different slot. $previous is the superseded booking.
 */
class CandidateRescheduled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly InterviewSlotBooking $booking,
        public readonly InterviewSlotBooking $previous,
    ) {}
}
