<?php

namespace App\Events;

use App\Models\Employee;
use App\Models\Interview;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by InterviewService after the change commits (Phase 5) — drives external calendar sync
 * (SyncInterviewCalendar) and candidate communications (SendInterviewCommunications). $cause is set
 * when the application closed (Phase 8.3 cascade), e.g. 'application_rejected'.
 */
class InterviewCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Interview $interview,
        public readonly ?Employee $actor = null,
        public readonly ?string $cause = null,
    ) {}
}
