<?php

namespace App\Services\Automation;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Enums\ReferralStatus;
use App\Enums\RequisitionStatus;
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
use App\Models\CandidateApplication;
use App\Models\CandidateCommunication;
use App\Models\CandidateJoining;
use App\Models\EmployeeReferral;
use App\Models\HiringRisk;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\RecruitmentRequisition;
use App\Services\Automation\Data\TriggerDefinition;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The single catalogue of what can start an automation (Phase 6). Event triggers map onto the
 * real domain events the services already dispatch after commit — no parallel event system;
 * schedule triggers describe time-based states the dispatch sweep finds with targeted, indexed
 * queries. Event payloads are reduced to a subject model plus scalar facts.
 */
class AutomationEventRegistry
{
    /**
     * @var array<string, TriggerDefinition>|null
     */
    private ?array $definitions = null;

    /**
     * @return Collection<string, TriggerDefinition>
     */
    public function all(): Collection
    {
        return collect($this->definitions ??= $this->build());
    }

    public function find(string $key): ?TriggerDefinition
    {
        return $this->all()->get($key);
    }

    /**
     * @return array<string, array<string, string>> group => [key => label], for the rule builder
     */
    public function options(): array
    {
        return $this->all()
            ->groupBy(fn (TriggerDefinition $definition) => $definition->group)
            ->map(fn (Collection $group) => $group->mapWithKeys(fn (TriggerDefinition $definition) => [$definition->key => $definition->label])->all())
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function eventTriggerKeys(): array
    {
        return $this->all()->reject(fn (TriggerDefinition $definition) => $definition->isScheduled())->keys()->all();
    }

    /**
     * Reduces a domain event to [trigger key, subject, scalar event data], or null when the event
     * is not an automation trigger.
     *
     * @return array{0: string, 1: Model, 2: array<string, scalar|null>}|null
     */
    public function fromEvent(object $event): ?array
    {
        return match (true) {
            $event instanceof CandidateStageChanged => ['candidate.stage_changed', $event->application, [
                'previous_stage' => $event->previousStage?->value,
                'new_stage' => $event->newStage->value,
                'previous_status' => $event->previousStatus->value,
            ]],
            $event instanceof CandidateAppliedOnline => ['application.applied_online', $event->application, []],
            $event instanceof InterviewScheduled => ['interview.scheduled', $event->interview, []],
            $event instanceof InterviewRescheduled => ['interview.rescheduled', $event->interview, []],
            $event instanceof InterviewCancelled => ['interview.cancelled', $event->interview, []],
            $event instanceof InterviewConfirmed => ['interview.confirmed', $event->interview, []],
            $event instanceof InterviewCompleted => ['interview.completed', $event->interview, []],
            $event instanceof OfferReleased => ['offer.released', $event->offer, []],
            $event instanceof OfferAccepted => ['offer.accepted', $event->offer, []],
            $event instanceof ReferralSubmitted => ['referral.submitted', $event->referral, []],
            $event instanceof ReferralStatusChanged => ['referral.status_changed', $event->referral, ['previous_status' => $event->from->value, 'new_status' => $event->to->value]],
            $event instanceof CommunicationFailed => ['communication.failed', $event->communication, []],
            $event instanceof RequisitionStatusChanged => ['requisition.status_changed', $event->requisition, ['previous_requisition_status' => $event->from->value, 'new_requisition_status' => $event->to->value]],
            $event instanceof HiringRiskDetected => ['intelligence.risk_detected', $event->risk, ['risk_type' => $event->risk->type->value, 'risk_severity' => $event->risk->severity->value]],
            default => null,
        };
    }

    /**
     * @return array<string, TriggerDefinition>
     */
    private function build(): array
    {
        $interviewAnchors = ['event' => 'When the event happens', 'interview.scheduled_at' => 'Interview time'];
        $offerAnchors = ['event' => 'When the event happens', 'offer.offer_expiry' => 'Offer expiry date', 'offer.expected_joining_date' => 'Expected joining date'];

        $definitions = [
            // Candidate & application events
            new TriggerDefinition('candidate.stage_changed', 'Candidate stage changed', 'Candidate', 'event', CandidateApplication::class,
                'Any stage move of an application, including joining confirmed and joined. Use conditions on the new stage.',
                CandidateStageChanged::class, ['event' => 'When the event happens']),
            new TriggerDefinition('application.applied_online', 'Candidate applied online', 'Candidate', 'event', CandidateApplication::class,
                'A candidate applied through the career site. The acknowledgement message is already sent automatically.',
                CandidateAppliedOnline::class, ['event' => 'When the event happens']),

            // Interview events
            new TriggerDefinition('interview.scheduled', 'Interview scheduled', 'Interview', 'event', Interview::class,
                'An interview was scheduled. The candidate confirmation message is already sent automatically.', InterviewScheduled::class, $interviewAnchors),
            new TriggerDefinition('interview.rescheduled', 'Interview rescheduled', 'Interview', 'event', Interview::class,
                'An interview moved to a new time.', InterviewRescheduled::class, $interviewAnchors),
            new TriggerDefinition('interview.cancelled', 'Interview cancelled', 'Interview', 'event', Interview::class,
                'An interview was cancelled.', InterviewCancelled::class, ['event' => 'When the event happens']),
            new TriggerDefinition('interview.confirmed', 'Interview confirmed', 'Interview', 'event', Interview::class,
                'An interview was confirmed.', InterviewConfirmed::class, $interviewAnchors),
            new TriggerDefinition('interview.completed', 'Interview completed', 'Interview', 'event', Interview::class,
                'An interview result was recorded.', InterviewCompleted::class, ['event' => 'When the event happens']),

            // Offer events
            new TriggerDefinition('offer.released', 'Offer released', 'Offer', 'event', Offer::class,
                'An offer was released to the candidate.', OfferReleased::class, $offerAnchors),
            new TriggerDefinition('offer.accepted', 'Offer accepted', 'Offer', 'event', Offer::class,
                'The candidate accepted the offer.', OfferAccepted::class, $offerAnchors),

            // Referral & communication events
            new TriggerDefinition('referral.submitted', 'Referral submitted', 'Referral', 'event', EmployeeReferral::class,
                'An employee submitted a referral.', ReferralSubmitted::class, ['event' => 'When the event happens']),
            new TriggerDefinition('referral.status_changed', 'Referral status changed', 'Referral', 'event', EmployeeReferral::class,
                'A referral moved to a new status.', ReferralStatusChanged::class, ['event' => 'When the event happens']),
            new TriggerDefinition('communication.failed', 'Candidate message failed', 'Communication', 'event', CandidateCommunication::class,
                'A candidate message failed or bounced.', CommunicationFailed::class, ['event' => 'When the event happens']),

            // Requisition & EDGE Intelligence events (Phase 7)
            new TriggerDefinition('requisition.status_changed', 'Requisition status changed', 'Requisition', 'event', RecruitmentRequisition::class,
                'A requisition moved to a new status (approval, open, hold, close, cancel).', RequisitionStatusChanged::class, ['event' => 'When the event happens']),
            new TriggerDefinition('intelligence.risk_detected', 'Hiring risk detected', 'EDGE Intelligence', 'event', HiringRisk::class,
                'The Hiring Risk Radar opened a new risk. Use conditions on the risk type and severity.', HiringRiskDetected::class, ['event' => 'When the event happens']),

            // Time-based (schedule) triggers
            new TriggerDefinition('interview.upcoming', 'Interview coming up', 'Time-based', 'schedule', Interview::class,
                'Fires once per interview time when a scheduled or confirmed interview is within the threshold. Rescheduling re-arms it.',
                sweep: fn (CarbonInterface $threshold): Builder => Interview::query()
                    ->whereIn('status', [...InterviewStatus::unconfirmed(), InterviewStatus::Confirmed])
                    ->whereBetween('scheduled_at', [now(), $threshold]),
                anchor: fn (Interview $interview) => $interview->scheduled_at,
                thresholdLabel: 'Interview starts within', thresholdIsLookahead: true),
            new TriggerDefinition('interview.feedback_pending', 'Interview result not recorded', 'Time-based', 'schedule', Interview::class,
                'An interview time has passed by the threshold but no result has been recorded (looks back 30 days).',
                sweep: fn (CarbonInterface $threshold): Builder => Interview::query()
                    ->whereNotIn('status', [InterviewStatus::Completed, InterviewStatus::Cancelled, InterviewStatus::NoShow, InterviewStatus::Hold])
                    ->whereBetween('scheduled_at', [now()->subDays(30), $threshold]),
                anchor: fn (Interview $interview) => $interview->scheduled_at,
                thresholdLabel: 'Interview ended more than'),
            new TriggerDefinition('application.stuck', 'Candidate stuck (no activity)', 'Time-based', 'schedule', CandidateApplication::class,
                'An active application has had no activity for longer than the threshold. Any new activity re-arms it.',
                sweep: fn (CarbonInterface $threshold): Builder => CandidateApplication::query()
                    ->where('status', ApplicationStatus::Active)
                    ->where('current_stage', '!=', CandidateStage::Joined)
                    ->where('last_activity_at', '<=', $threshold),
                anchor: fn (CandidateApplication $application) => $application->last_activity_at,
                thresholdLabel: 'No activity for more than'),
            new TriggerDefinition('application.selected_without_offer', 'Selected but no offer', 'Time-based', 'schedule', CandidateApplication::class,
                'A candidate has been Selected for longer than the threshold without an offer being initiated.',
                sweep: fn (CarbonInterface $threshold): Builder => CandidateApplication::query()
                    ->where('status', ApplicationStatus::Active)
                    ->where('current_stage', CandidateStage::Selected)
                    ->where('last_activity_at', '<=', $threshold)
                    ->whereDoesntHave('offers', fn (Builder $offers) => $offers->where('status', '!=', OfferStatus::Withdrawn)),
                anchor: fn (CandidateApplication $application) => $application->last_activity_at,
                thresholdLabel: 'Selected for more than'),
            new TriggerDefinition('offer.awaiting_response', 'Offer awaiting response', 'Time-based', 'schedule', Offer::class,
                'A released offer has had no decision for longer than the threshold.',
                sweep: fn (CarbonInterface $threshold): Builder => Offer::query()
                    ->where('status', OfferStatus::Released)
                    ->whereDate('offer_date', '<=', $threshold->toDateString()),
                anchor: fn (Offer $offer) => $offer->offer_date,
                thresholdLabel: 'Released more than'),
            new TriggerDefinition('joining.upcoming', 'Joining date coming up', 'Time-based', 'schedule', CandidateJoining::class,
                'An expected or confirmed joiner\'s date is within the threshold. Moving the date re-arms it.',
                sweep: fn (CarbonInterface $threshold): Builder => CandidateJoining::query()
                    ->whereIn('status', [JoiningStatus::Expected, JoiningStatus::Confirmed])
                    ->whereBetween('expected_doj', [now()->toDateString(), $threshold->toDateString()]),
                anchor: fn (CandidateJoining $joining) => $joining->expected_doj,
                thresholdLabel: 'Joining within', thresholdIsLookahead: true),
            new TriggerDefinition('requisition.ageing', 'Vacancy ageing', 'Time-based', 'schedule', RecruitmentRequisition::class,
                'An open requisition has been open for longer than the threshold.',
                sweep: fn (CarbonInterface $threshold): Builder => RecruitmentRequisition::query()
                    ->where('status', RequisitionStatus::Open)
                    ->whereDate('opening_date', '<=', $threshold->toDateString()),
                anchor: fn (RecruitmentRequisition $requisition) => $requisition->opening_date,
                thresholdLabel: 'Open for more than'),
            new TriggerDefinition('referral.awaiting_review', 'Referral awaiting review', 'Time-based', 'schedule', EmployeeReferral::class,
                'A submitted referral has not been reviewed within the threshold.',
                sweep: fn (CarbonInterface $threshold): Builder => EmployeeReferral::query()
                    ->where('status', ReferralStatus::Submitted)
                    ->where('created_at', '<=', $threshold),
                anchor: fn (EmployeeReferral $referral) => $referral->created_at,
                thresholdLabel: 'Submitted more than'),
        ];

        return collect($definitions)->keyBy(fn (TriggerDefinition $definition) => $definition->key)->all();
    }
}
