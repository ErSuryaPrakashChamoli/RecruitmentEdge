<?php

namespace App\Jobs;

use App\Enums\InterviewStatus;
use App\Models\Interview;
use App\Services\Integrations\Calendar\CalendarSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Queued external-calendar sync for one interview change (Phase 5). Retries temporary provider
 * failures with backoff; the event mapping (interview_calendar_events) makes retries update the
 * same external event instead of creating another. A calendar failure never touches the
 * interview's own schedule or status.
 *
 * Phase 8.7 (D8.7-005): what to do is decided from the interview as it is when the job runs, not
 * when it was queued — a create that runs after a cancellation cancels. One job per interview waits
 * in the queue at a time, and two never run together for the same interview, so a burst of changes
 * never creates a second external event.
 */
class SyncInterviewCalendarJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly int $interviewId, public readonly string $action)
    {
        $this->onQueue('integrations');
    }

    public function uniqueId(): string
    {
        return (string) $this->interviewId;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("calendar-sync:{$this->interviewId}"))->releaseAfter(30)->expireAfter(300)];
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(CalendarSyncService $sync): void
    {
        $interview = Interview::query()->find($this->interviewId);

        if ($interview === null) {
            return;
        }

        $action = in_array($interview->status, [InterviewStatus::Cancelled, InterviewStatus::NoShow], true) ? 'cancel' : 'update';
        $result = $sync->sync($interview, $action);

        if ($result !== null && ! $result->ok && $result->retryable) {
            throw new RuntimeException("Temporary calendar provider failure for interview {$this->interviewId}: {$result->error}");
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Interview calendar sync gave up', ['interview_id' => $this->interviewId, 'action' => $this->action, 'error' => $exception !== null ? $exception::class : null]);
    }
}
