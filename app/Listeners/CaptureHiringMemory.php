<?php

namespace App\Listeners;

use App\Enums\ApplicationStatus;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Enums\RequisitionStatus;
use App\Events\CandidateJoined;
use App\Events\CandidateStageChanged;
use App\Events\OfferStatusChanged;
use App\Events\RequisitionStatusChanged;
use App\Models\CandidateJoining;
use App\Services\Intelligence\HiringMemoryService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Captures Hiring Memory™ (Phase 7) from the real domain events, after they commit, on the
 * intelligence queue: hires, rejections/dropouts, failed joinings, offers not converted, and
 * requisitions closing. Since Phase 8.2 a hire is captured from the joining record (CandidateJoined),
 * never from the Joined pipeline stage — stage moves have known bypass paths. Capture is idempotent, so a replayed event records nothing twice.
 * Auto-discovered — do not also register it.
 */
class CaptureHiringMemory implements ShouldBeEncrypted, ShouldQueue
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

    public function __construct(private readonly HiringMemoryService $memory) {}

    public function handleCandidateStageChanged(CandidateStageChanged $event): void
    {
        $application = $event->application->fresh();

        if ($application === null) {
            return;
        }

        if (! $event->statusChanged()) {
            return;
        }

        if ($event->newStatus === ApplicationStatus::Rejected) {
            $this->memory->captureRejection($application, $event->remarks);
        }

        if ($event->newStatus === ApplicationStatus::Dropout) {
            $joiningFailed = in_array($application->joining?->status, [JoiningStatus::NoShow, JoiningStatus::Dropout], true);
            $joiningFailed ? $this->memory->captureJoiningOutcome($application) : $this->memory->captureRejection($application, $event->remarks);
        }
    }

    public function handleCandidateJoined(CandidateJoined $event): void
    {
        $joining = CandidateJoining::query()->with('candidateApplication')->find($event->joiningId);

        if ($joining?->candidateApplication !== null) {
            $this->memory->captureHire($joining->candidateApplication, $joining);
        }
    }

    public function handleOfferStatusChanged(OfferStatusChanged $event): void
    {
        if (in_array($event->to, [OfferStatus::Rejected, OfferStatus::Expired, OfferStatus::Withdrawn], true)) {
            $this->memory->captureOfferOutcome($event->offer, $event->to, $event->remarks);
        }
    }

    public function handleRequisitionStatusChanged(RequisitionStatusChanged $event): void
    {
        $outcome = match ($event->to) {
            RequisitionStatus::Closed => 'closed',
            RequisitionStatus::Cancelled => 'cancelled',
            default => null,
        };

        if ($outcome !== null) {
            $this->memory->captureRequisitionOutcome($event->requisition, $outcome);
        }
    }
}
