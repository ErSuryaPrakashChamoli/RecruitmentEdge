<?php

namespace App\Jobs;

use App\Models\Interview;
use App\Services\Integrations\Calendar\CalendarSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Queued external-calendar sync for one interview change (Phase 5). Retries temporary provider
 * failures with backoff; the event mapping (interview_calendar_events) makes retries update the
 * same external event instead of creating another. A calendar failure never touches the
 * interview's own schedule or status.
 */
class SyncInterviewCalendarJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly int $interviewId, public readonly string $action)
    {
        $this->onQueue('integrations');
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

        $result = $sync->sync($interview, $this->action);

        if ($result !== null && ! $result->ok && $result->retryable) {
            throw new RuntimeException("Temporary calendar provider failure for interview {$this->interviewId}: {$result->error}");
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Interview calendar sync gave up', ['interview_id' => $this->interviewId, 'action' => $this->action, 'error' => $exception?->getMessage()]);
    }
}
