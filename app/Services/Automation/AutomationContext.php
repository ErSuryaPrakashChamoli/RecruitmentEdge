<?php

namespace App\Services\Automation;

use App\Enums\InterviewStatus;
use App\Models\AutomationExecution;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateCommunication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\EmployeeReferral;
use App\Models\HiringRisk;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\RecruitmentRequisition;
use App\Services\Communication\MessageContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The records one automation run is about, resolved from the triggering entity (the "subject").
 * Conditions, actions and recipient resolution read only from here, and only this safe set of IDs
 * is persisted on the execution — never names, contact details or other candidate data.
 *
 * For an application-level subject, interview/offer/joining resolve to the application's most
 * relevant one: the next upcoming (or latest) interview, the latest offer, the joining record.
 */
final class AutomationContext
{
    private ?CandidateApplication $application = null;

    private ?Candidate $candidate = null;

    private ?RecruitmentRequisition $requisition = null;

    private ?Interview $interview = null;

    private ?Offer $offer = null;

    private ?CandidateJoining $joining = null;

    private ?EmployeeReferral $referral = null;

    private ?CandidateCommunication $communication = null;

    private ?HiringRisk $risk = null;

    private bool $interviewResolved = false;

    private bool $offerResolved = false;

    private bool $joiningResolved = false;

    /**
     * @param  array<string, scalar|null>  $eventData  Scalar facts from the event (e.g. previous stage)
     */
    public function __construct(
        public readonly string $trigger,
        public readonly Model $subject,
        public readonly CarbonInterface $occurredAt,
        public readonly ?CarbonInterface $anchorAt = null,
        public readonly array $eventData = [],
    ) {
        match (true) {
            $subject instanceof CandidateApplication => $this->application = $subject,
            $subject instanceof Interview => [$this->interview, $this->interviewResolved, $this->application] = [$subject, true, $subject->candidateApplication],
            $subject instanceof Offer => [$this->offer, $this->offerResolved, $this->application] = [$subject, true, $subject->candidateApplication],
            $subject instanceof CandidateJoining => [$this->joining, $this->joiningResolved, $this->application] = [$subject, true, $subject->candidateApplication],
            $subject instanceof EmployeeReferral => [$this->referral, $this->application, $this->candidate, $this->requisition] = [$subject, $subject->candidateApplication, $subject->candidate, $subject->requisition],
            $subject instanceof CandidateCommunication => [$this->communication, $this->application, $this->candidate] = [$subject, $subject->candidateApplication, $subject->candidate],
            $subject instanceof RecruitmentRequisition => $this->requisition = $subject,
            $subject instanceof HiringRisk => [$this->risk, $this->application, $this->requisition] = [$subject, $subject->candidateApplication, $subject->requisition],
            default => null,
        };

        $this->candidate ??= $this->application?->candidate;
        $this->requisition ??= $this->application?->requisition;
    }

    /**
     * @param  array<string, scalar|null>  $eventData
     */
    public static function for(Model $subject, string $trigger, array $eventData = [], ?CarbonInterface $occurredAt = null, ?CarbonInterface $anchorAt = null): self
    {
        return new self($trigger, $subject, $occurredAt ?? now(), $anchorAt, $eventData);
    }

    /**
     * Rebuilds the context of a stored execution from fresh database state, so a delayed run
     * evaluates its conditions against what is true now, not what was true when it was queued.
     */
    public static function fromExecution(AutomationExecution $execution): ?self
    {
        $subject = $execution->subject()->first();

        if ($subject === null) {
            return null;
        }

        $context = $execution->context ?? [];

        return new self(
            $execution->trigger,
            $subject,
            $execution->triggered_at ?? $execution->created_at,
            filled($context['anchor_at'] ?? null) ? Carbon::parse($context['anchor_at']) : null,
            $context['event'] ?? [],
        );
    }

    public function application(): ?CandidateApplication
    {
        return $this->application;
    }

    public function candidate(): ?Candidate
    {
        return $this->candidate;
    }

    public function requisition(): ?RecruitmentRequisition
    {
        return $this->requisition;
    }

    public function recruiter(): ?Employee
    {
        return $this->application?->recruiter;
    }

    public function referral(): ?EmployeeReferral
    {
        return $this->referral;
    }

    public function communication(): ?CandidateCommunication
    {
        return $this->communication;
    }

    public function risk(): ?HiringRisk
    {
        return $this->risk;
    }

    public function interview(): ?Interview
    {
        if (! $this->interviewResolved) {
            $this->interviewResolved = true;
            $this->interview = $this->application === null ? null : ($this->application->interviews()
                ->whereNotIn('status', [InterviewStatus::Cancelled, InterviewStatus::Completed, InterviewStatus::NoShow])
                ->orderBy('scheduled_at')
                ->first() ?? $this->application->interviews()->latest('scheduled_at')->first());
        }

        return $this->interview;
    }

    public function offer(): ?Offer
    {
        if (! $this->offerResolved) {
            $this->offerResolved = true;
            $this->offer = $this->joining?->offer ?? $this->application?->offers()->latest('id')->first();
        }

        return $this->offer;
    }

    public function joining(): ?CandidateJoining
    {
        if (! $this->joiningResolved) {
            $this->joiningResolved = true;
            $this->joining = $this->application?->joining;
        }

        return $this->joining;
    }

    /**
     * The candidate-facing message context, or null when the subject has no candidate.
     */
    public function messageContext(): ?MessageContext
    {
        if ($this->candidate === null) {
            return null;
        }

        return new MessageContext($this->candidate, $this->application, $this->interview(), $this->offer(), $this->joining());
    }

    /**
     * Safe identifiers only — this is what an execution stores.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'application_id' => $this->application?->id,
            'candidate_id' => $this->candidate?->id,
            'requisition_id' => $this->requisition?->id,
            'recruiter_id' => $this->application?->recruiter_id,
            'interview_id' => $this->interviewResolved ? $this->interview?->id : null,
            'offer_id' => $this->offerResolved ? $this->offer?->id : null,
            'joining_id' => $this->joiningResolved ? $this->joining?->id : null,
            'referral_id' => $this->referral?->id,
            'communication_id' => $this->communication?->id,
            'risk_id' => $this->risk?->id,
            'anchor_at' => $this->anchorAt?->toIso8601String(),
            'event' => $this->eventData ?: null,
        ], fn ($value) => $value !== null);
    }
}
