<?php

namespace App\Listeners;

use App\Events\InterviewCancelled;
use App\Events\InterviewRescheduled;
use App\Events\InterviewScheduled;
use App\Jobs\SyncInterviewCalendarJob;

/**
 * Queues the external calendar/video sync for every interview change (Phase 5). Discovered
 * automatically — do not also register it.
 */
class SyncInterviewCalendar
{
    public function handleScheduled(InterviewScheduled $event): void
    {
        SyncInterviewCalendarJob::dispatch($event->interview->id, 'create');
    }

    public function handleRescheduled(InterviewRescheduled $event): void
    {
        SyncInterviewCalendarJob::dispatch($event->interview->id, 'update');
    }

    public function handleCancelled(InterviewCancelled $event): void
    {
        SyncInterviewCalendarJob::dispatch($event->interview->id, 'cancel');
    }
}
