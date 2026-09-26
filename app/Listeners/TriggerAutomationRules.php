<?php

namespace App\Listeners;

use App\Events\CandidateAppliedOnline;
use App\Events\CandidateStageChanged;
use App\Events\CommunicationFailed;
use App\Events\HiringRiskDetected;
use App\Events\InterviewCancelled;
use App\Events\InterviewCompleted;
use App\Events\InterviewConfirmed;
use App\Events\InterviewRescheduled;
use App\Events\InterviewScheduled;
use App\Events\OfferAccepted;
use App\Events\OfferReleased;
use App\Events\ReferralStatusChanged;
use App\Events\ReferralSubmitted;
use App\Events\RequisitionStatusChanged;
use App\Services\Automation\AutomationEngine;
use Throwable;

/**
 * Feeds the real domain events into the automation engine (Phase 6). Deliberately synchronous: it
 * only looks up Active rules for the trigger (cached, usually none) and inserts execution rows —
 * the actions themselves run on the automation queue. Running in-process is what lets loop
 * prevention see the automation chain an event came from. A failure here is reported, never
 * allowed to break the business operation that raised the event. Auto-discovered; do not also
 * register it.
 */
class TriggerAutomationRules
{
    public function __construct(private readonly AutomationEngine $engine) {}

    public function handleCandidateStageChanged(CandidateStageChanged $event): void
    {
        $this->feed($event);
    }

    public function handleCandidateAppliedOnline(CandidateAppliedOnline $event): void
    {
        $this->feed($event);
    }

    public function handleInterviewScheduled(InterviewScheduled $event): void
    {
        $this->feed($event);
    }

    public function handleInterviewRescheduled(InterviewRescheduled $event): void
    {
        $this->feed($event);
    }

    public function handleInterviewCancelled(InterviewCancelled $event): void
    {
        $this->feed($event);
    }

    public function handleInterviewConfirmed(InterviewConfirmed $event): void
    {
        $this->feed($event);
    }

    public function handleInterviewCompleted(InterviewCompleted $event): void
    {
        $this->feed($event);
    }

    public function handleOfferReleased(OfferReleased $event): void
    {
        $this->feed($event);
    }

    public function handleOfferAccepted(OfferAccepted $event): void
    {
        $this->feed($event);
    }

    public function handleReferralSubmitted(ReferralSubmitted $event): void
    {
        $this->feed($event);
    }

    public function handleReferralStatusChanged(ReferralStatusChanged $event): void
    {
        $this->feed($event);
    }

    public function handleCommunicationFailed(CommunicationFailed $event): void
    {
        $this->feed($event);
    }

    public function handleRequisitionStatusChanged(RequisitionStatusChanged $event): void
    {
        $this->feed($event);
    }

    public function handleHiringRiskDetected(HiringRiskDetected $event): void
    {
        $this->feed($event);
    }

    private function feed(object $event): void
    {
        try {
            $this->engine->handleEvent($event);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
