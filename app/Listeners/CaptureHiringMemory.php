<?php

namespace App\Listeners;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Enums\RequisitionStatus;
use App\Events\CandidateStageChanged;
use App\Events\OfferStatusChanged;
use App\Events\RequisitionStatusChanged;
use App\Services\Intelligence\HiringMemoryService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Captures Hiring Memory™ (Phase 7) from the real domain events, after they commit, on the
 * intelligence queue: hires, rejections/dropouts, failed joinings, offers not converted, and
 * requisitions closing. Capture is idempotent, so a replayed event records nothing twice.
 * Auto-discovered — do not also register it.
 */
class CaptureHiringMemory implements ShouldQueue
{
    public string $queue = 'intelligence';

    public function __construct(private readonly HiringMemoryService $memory) {}

    public function handleCandidateStageChanged(CandidateStageChanged $event): void
    {
        $application = $event->application->fresh();

        if ($application === null) {
            return;
        }

        if ($event->newStage === CandidateStage::Joined && $event->previousStage !== CandidateStage::Joined) {
            $this->memory->captureHire($application);
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
