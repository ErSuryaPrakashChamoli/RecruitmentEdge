<?php

namespace App\Events;

use App\Models\Employee;
use App\Models\Interview;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by InterviewService after the change commits (Phase 5) — drives external calendar sync
 * (SyncInterviewCalendar) and candidate communications (SendInterviewCommunications).
 */
class InterviewScheduled implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Interview $interview,
        public readonly ?Employee $actor = null,
    ) {}
}
