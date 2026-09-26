<?php

namespace App\Events;

use App\Models\InterviewAvailabilitySlot;
use App\Models\InterviewSlotBooking;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A slot was withdrawn by the recruiter, or a booking of it was cancelled (then $booking is set).
 */
class InterviewSlotCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly InterviewAvailabilitySlot $slot,
        public readonly ?InterviewSlotBooking $booking = null,
    ) {}
}
