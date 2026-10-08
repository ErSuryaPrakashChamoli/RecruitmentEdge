<?php

namespace App\Policies;

use App\Policies\Concerns\ReadOnlyRecord;

/**
 * Phase 8.6 (D8.6-027): listed inside its owner record's page; changes go through that page's own
 * actions (and their service), never generic create/edit/delete.
 */
class InterviewSlotBookingPolicy
{
    use ReadOnlyRecord;
}
