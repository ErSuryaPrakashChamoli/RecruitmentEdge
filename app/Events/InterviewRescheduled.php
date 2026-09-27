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
class InterviewRescheduled implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  int|null  $rescheduledAt  Phase 8.7: when this reschedule happened (Unix time) — the
     *                                   identity of this one reschedule for its candidate notice.
     */
    public function __construct(
        public readonly Interview $interview,
        public readonly ?Employee $actor = null,
        public readonly ?int $rescheduledAt = null,
    ) {}
}
