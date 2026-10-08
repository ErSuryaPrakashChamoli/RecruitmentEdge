<?php

namespace App\Listeners;

use App\Enums\JoiningStatus;
use App\Events\CandidateJoined;
use App\Events\CandidateStageChanged;
use App\Events\EmployeeConvertedFromCandidate;
use App\Events\OfferStatusChanged;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\HiringOutcomeSnapshot;
use App\Services\Outcomes\HiringSnapshotService;
use App\Services\Outcomes\OutcomeCalculator;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Outcome Loop™ (Phase 8.2): records outcomes as they happen, after commit, on the intelligence
 * queue. Every trigger reloads its source record by id and derives the outcome from that record
 * (the joining record, the offer status history) — an event or a stage value is never taken as
 * proof on its own. Idempotent; the daily outcomes:evaluate catches up on anything missed.
 * Auto-discovered — do not also register it.
 */
class RecordHiringOutcomes implements ShouldBeEncrypted, ShouldQueue
{
    public string $queue = 'intelligence';

    /**
     * Phase 8.7 (D8.7-003/004): internal writes retry 3 times with backoff, never immediately.
     */
    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60];

    /**
     * Phase 8.7 (D8.7-013): an exhausted listener is recorded — event and ids only, never content.
     */
    public function failed(object $event, Throwable $exception): void
    {
        Log::error('queue.listener_failed', ['listener' => static::class, 'event' => $event::class, 'error' => $exception::class]);
    }

    public function __construct(
        private readonly HiringSnapshotService $snapshots,
        private readonly OutcomeCalculator $calculator,
    ) {}

    public function handleCandidateJoined(CandidateJoined $event): void
    {
        $joining = CandidateJoining::query()->find($event->joiningId);

        if ($joining === null || $joining->status !== JoiningStatus::Joined) {
            return;
        }

        $this->calculator->joining($joining);

        if ($snapshot = $this->snapshots->captureForJoining($joining)) {
            $this->calculator->process($snapshot);
        }
    }

    public function handleEmployeeConvertedFromCandidate(EmployeeConvertedFromCandidate $event): void
    {
        $snapshot = HiringOutcomeSnapshot::query()->where('candidate_application_id', $event->applicationId)->first();

        if ($snapshot === null) {
            $joining = CandidateJoining::query()->where('candidate_application_id', $event->applicationId)->first();
            $snapshot = $joining !== null ? $this->snapshots->captureForJoining($joining) : null;
        }

        if ($snapshot !== null && $snapshot->employee_id === null) {
            $snapshot->update(['employee_id' => $event->employeeId]);
        }
    }

    /**
     * No-show and dropout: the joining record, not the application status, decides.
     */
    public function handleCandidateStageChanged(CandidateStageChanged $event): void
    {
        $joining = CandidateApplication::query()->find($event->application->id)?->joining;

        if ($joining !== null && in_array($joining->status, [JoiningStatus::NoShow, JoiningStatus::Dropout], true)) {
            $this->calculator->joining($joining);
        }
    }

    public function handleOfferStatusChanged(OfferStatusChanged $event): void
    {
        $this->calculator->offer($event->offer->fresh());
    }
}
