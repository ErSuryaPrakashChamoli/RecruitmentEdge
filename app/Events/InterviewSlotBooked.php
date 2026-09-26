<?php

namespace App\Events;

use App\Models\InterviewSlotBooking;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A candidate (or recruiter on their behalf) booked an availability slot — hook for Phase 5
 * calendar sync / confirmation messages and the Phase 6 Notification Center.
 */
class InterviewSlotBooked implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly InterviewSlotBooking $booking) {}
}
