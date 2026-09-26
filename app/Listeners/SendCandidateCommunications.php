<?php

namespace App\Listeners;

use App\Events\CandidateAppliedOnline;
use App\Events\InterviewCancelled;
use App\Events\InterviewRescheduled;
use App\Events\InterviewScheduled;
use App\Events\OfferReleased;
use App\Filament\Resources\CandidateApplications\CandidateApplicationResource;
use App\Services\Communication\CommunicationService;
use App\Services\Communication\MessageContext;
use App\Services\NotificationDispatchService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Event-driven candidate communications (Phase 5E). Each domain event sends the active template
 * for its purpose on every channel that has one (inactive/missing templates are skipped), with a
 * stable idempotency key so a replayed event or a retried listener never sends twice:
 *
 * - one confirmation per interview (self-scheduled bookings also go through
 *   InterviewService::schedule(), so they are covered here — no second path);
 * - one reschedule notice per new interview time;
 * - one cancellation, one offer-released and one application-received message.
 *
 * Queued on the communications queue so domain requests never wait on rendering. Discovered
 * automatically — do not also register it.
 */
class SendCandidateCommunications implements ShouldQueue
{
    public string $queue = 'communications';

    /**
     * Template keys this listener already sends automatically. Automation rules may not send them
     * too (AutomationRuleValidator), so a candidate never gets the same message twice.
     *
     * @var array<int, string>
     */
    public const array TEMPLATE_KEYS = ['interview_scheduled', 'interview_rescheduled', 'interview_cancelled', 'offer_released', 'application_received'];

    public function __construct(
        private readonly CommunicationService $communications,
        private readonly NotificationDispatchService $notifications,
    ) {}

    public function handleInterviewScheduled(InterviewScheduled $event): void
    {
        $this->communications->sendAutomatic('interview_scheduled', MessageContext::forInterview($event->interview), "interview.scheduled:{$event->interview->id}");
    }

    public function handleInterviewRescheduled(InterviewRescheduled $event): void
    {
        $interview = $event->interview;

        $this->communications->sendAutomatic('interview_rescheduled', MessageContext::forInterview($interview), "interview.rescheduled:{$interview->id}:{$interview->scheduled_at->timestamp}");
    }

    public function handleInterviewCancelled(InterviewCancelled $event): void
    {
        $this->communications->sendAutomatic('interview_cancelled', MessageContext::forInterview($event->interview), "interview.cancelled:{$event->interview->id}");
    }

    public function handleOfferReleased(OfferReleased $event): void
    {
        $application = $event->offer->candidateApplication;

        $this->communications->sendAutomatic('offer_released', new MessageContext($application->candidate, $application, offer: $event->offer), "offer.released:{$event->offer->id}");
    }

    public function handleAppliedOnline(CandidateAppliedOnline $event): void
    {
        $application = $event->application->loadMissing('candidate', 'requisition', 'recruiter.user');

        $this->communications->sendAutomatic('application_received', new MessageContext($application->candidate, $application), "application.received:{$application->id}");

        $this->notifications->alert(
            $application->recruiter?->user,
            'Recruitment',
            'New online application',
            "{$application->candidate->full_name} applied for {$application->requisition?->code} through the career site.",
            'info',
            CandidateApplicationResource::getUrl('view', ['record' => $application]),
            "online-application-{$application->id}",
        );
    }
}
