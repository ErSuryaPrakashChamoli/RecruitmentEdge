<?php

namespace App\Services\NextBestAction;

use App\Enums\ActionPriority;
use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\FollowupStatus;
use App\Enums\HiringRiskType;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Enums\RecruiterActionType;
use App\Enums\RiskSeverity;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\HiringRisk;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\RecruitmentFollowup;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\RecruitmentAnalyticsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Deterministic Next Best Action (Phase 6) — the single place that turns an application's recorded
 * state (status, stage, follow-ups, interviews, offers, joining, activity) into prioritised,
 * owned, explainable recommendations. The AI copilot's recommend_next_step tool and the Action
 * Center both read from here, so there is one set of rules. No AI is involved.
 */
class NextBestActionService
{
    public const int STALE_AFTER_DAYS = 7;

    /**
     * Relations forApplication() reads — eager-load these when evaluating many applications.
     *
     * @var array<int|string, mixed>
     */
    public const array RELATIONS = ['candidate:id,full_name', 'recruiter', 'joining', 'interviews.interviewer', 'offers'];

    public function __construct(
        private readonly HierarchyService $hierarchy,
        private readonly RecruitmentAnalyticsService $analytics,
    ) {}

    /**
     * Every recommendation for one application, most urgent first (never empty). Open Risk Radar
     * risks on the application become evidence-backed recommendations (pass them pre-loaded to
     * avoid a query per application).
     *
     * @param  Collection<int, HiringRisk>|null  $risks
     * @return Collection<int, NextBestAction>
     */
    public function forApplication(CandidateApplication $application, ?Collection $risks = null): Collection
    {
        $application->loadMissing([...self::RELATIONS, 'followups' => fn ($followups) => $followups->where('status', FollowupStatus::Pending)]);
        $recruiter = $application->recruiter;

        if (in_array($application->status, [ApplicationStatus::Rejected, ApplicationStatus::Dropout], true)) {
            return collect([new NextBestAction('closed_application', ActionPriority::Low, RecruiterActionType::Custom, 'No action needed — this application is closed.', "Status is {$application->status->label()} at the {$application->current_stage->label()} stage.", $application, $recruiter)]);
        }

        $actions = [];

        if ($application->status === ApplicationStatus::OnHold) {
            $actions[] = new NextBestAction('on_hold', ActionPriority::Medium, RecruiterActionType::ReviewApplication, 'Review the hold and decide whether to resume or close this application.', 'The application is on hold.', $application, $recruiter, suggestedTool: 'move_candidates_stage');
        }

        $overdueFollowups = $application->followups->filter(fn (RecruitmentFollowup $followup) => $followup->isOverdue());

        if ($overdueFollowups->isNotEmpty()) {
            $oldest = $overdueFollowups->sortBy('followup_date')->first();
            $actions[] = new NextBestAction('overdue_followup', ActionPriority::High, RecruiterActionType::FollowUp, "Complete the overdue {$oldest->followup_type->label()} follow-up.", "{$overdueFollowups->count()} follow-up(s) overdue, oldest due {$oldest->followup_date->toDayDateTimeString()}.", $application, $recruiter, $oldest->followup_date, 'list_overdue_followups');
        }

        $interviews = $application->interviews;
        $awaitingFeedback = $interviews->first(fn (Interview $interview) => $interview->status === InterviewStatus::Completed && $interview->result === null);

        if ($awaitingFeedback !== null) {
            $actions[] = new NextBestAction('feedback_pending', ActionPriority::High, RecruiterActionType::CollectFeedback, "Collect feedback and record a result for interview round {$awaitingFeedback->round_number}.", 'The interview is completed but has no result yet.', $awaitingFeedback, $awaitingFeedback->interviewer ?? $recruiter, now(), 'summarize_interview_feedback');
        }

        $unconfirmedPast = $interviews->first(fn (Interview $interview) => $interview->status->awaitsConfirmation() && $interview->scheduled_at?->isPast());

        if ($unconfirmedPast !== null) {
            $actions[] = new NextBestAction('interview_outcome_missing', ActionPriority::High, RecruiterActionType::CollectFeedback, "Update interview round {$unconfirmedPast->round_number}: it was scheduled for {$unconfirmedPast->scheduled_at->toDayDateTimeString()} but never confirmed or completed.", 'Interview time has passed without a confirmation or outcome.', $unconfirmedPast, $recruiter, now(), 'search_interviews');
        }

        $upcoming = $interviews
            ->filter(fn (Interview $interview) => ! $interview->status->isTerminal() && $interview->scheduled_at?->isFuture())
            ->sortBy('scheduled_at')
            ->first();

        if ($upcoming !== null) {
            $actions[] = $upcoming->status->awaitsConfirmation()
                ? new NextBestAction('interview_unconfirmed', $upcoming->scheduled_at->lte(now()->addDay()) ? ActionPriority::High : ActionPriority::Medium, RecruiterActionType::ConfirmInterview, "Confirm interview round {$upcoming->round_number} with the candidate and interviewer.", "Interview is {$upcoming->status->label()} for {$upcoming->scheduled_at->toDayDateTimeString()}.", $upcoming, $recruiter, $upcoming->scheduled_at->copy()->subHours(4), 'generate_interview_questions')
                : new NextBestAction('interview_prepare', ActionPriority::Low, RecruiterActionType::Custom, "Prepare for interview round {$upcoming->round_number} on {$upcoming->scheduled_at->toDayDateTimeString()}.", "Interview is {$upcoming->status->label()}.", $upcoming, $recruiter, $upcoming->scheduled_at, 'generate_interview_questions');
        }

        $openOffer = $application->offers->first(fn (Offer $offer) => in_array($offer->status, [OfferStatus::Initiated, OfferStatus::Released], true));

        if ($openOffer !== null) {
            $expired = $openOffer->offer_expiry !== null && $openOffer->offer_expiry->endOfDay()->isPast();
            $validity = $openOffer->offer_expiry !== null ? ", valid until {$openOffer->offer_expiry->toDateString()}." : '.';

            $actions[] = match (true) {
                $expired => new NextBestAction('offer_lapsed', ActionPriority::High, RecruiterActionType::ChaseOfferDecision, 'The offer validity has lapsed — follow up for a decision, or extend/withdraw the offer.', "Offer {$openOffer->offer_code} expired on {$openOffer->offer_expiry->toDateString()}.", $openOffer, $recruiter, now(), 'create_followup'),
                $openOffer->status === OfferStatus::Initiated => new NextBestAction('offer_release', ActionPriority::Medium, RecruiterActionType::InitiateOffer, 'Release the initiated offer.', "Offer {$openOffer->offer_code} is {$openOffer->status->label()}{$validity}", $openOffer, $recruiter, suggestedTool: 'create_followup'),
                default => new NextBestAction('offer_decision', ActionPriority::Medium, RecruiterActionType::ChaseOfferDecision, 'Chase the candidate for an offer decision.', "Offer {$openOffer->offer_code} is {$openOffer->status->label()}{$validity}", $openOffer, $recruiter, $openOffer->offer_expiry, 'create_followup'),
            };
        }

        $joining = $application->joining;

        if ($joining !== null && ! in_array($joining->status, [JoiningStatus::Joined, JoiningStatus::NoShow, JoiningStatus::Dropout], true)) {
            $risk = $joining->riskLevel();

            if (in_array($risk, ['yellow', 'red'], true)) {
                $actions[] = new NextBestAction('joining_risk', $risk === 'red' ? ActionPriority::High : ActionPriority::Medium, RecruiterActionType::ConfirmJoining, 'Call the candidate to reconfirm their joining date.', "Joining is {$joining->status->label()} with {$risk} risk (expected {$joining->expected_doj?->toDateString()}).", $joining, $recruiter, $joining->expected_doj?->copy()->subDay(), 'find_joining_risks');
            }
        }

        if ($application->status === ApplicationStatus::Active && $application->last_activity_at !== null && $application->last_activity_at->lte(now()->subDays(self::STALE_AFTER_DAYS))) {
            $actions[] = new NextBestAction('stale', ActionPriority::Medium, RecruiterActionType::FollowUp, 'Re-engage the candidate — there has been no activity for over a week.', 'Last activity '.$application->last_activity_at->diffForHumans().'.', $application, $recruiter, now(), 'create_followup');
        }

        foreach ($risks ?? HiringRisk::query()->open()->where('candidate_application_id', $application->id)->with('evidence')->get() as $risk) {
            $actions[] = $this->fromRisk($risk, $application, $recruiter);
        }

        $actions[] = $this->stageDefault($application, $upcoming !== null || $openOffer !== null);

        return collect($actions)
            ->sortBy(fn (NextBestAction $action, int $index) => [$action->priority->rank(), $index])
            ->values();
    }

    /**
     * The most urgent recommendation per visible active application plus positions whose pipeline
     * is too thin — for the Action Center. Bounded, eager-loaded (no N+1).
     *
     * @return Collection<int, NextBestAction>
     */
    public function forUser(User $user, int $limit = 10): Collection
    {
        $visible = $this->hierarchy->visibleEmployeeIdsFor($user);

        $applications = CandidateApplication::query()
            ->where('status', ApplicationStatus::Active)
            ->where('current_stage', '!=', CandidateStage::OnboardingCompleted)
            ->when($visible !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visible))
            ->with([...self::RELATIONS, 'followups' => fn ($followups) => $followups->where('status', FollowupStatus::Pending)])
            ->orderBy('last_activity_at')
            ->limit(100)
            ->get();

        $risks = HiringRisk::query()->open()->whereIn('candidate_application_id', $applications->pluck('id'))->with('evidence')->get()->groupBy('candidate_application_id');

        $recommendations = $applications
            ->map(fn (CandidateApplication $application) => $this->forApplication($application, $risks->get($application->id, collect()))->first())
            ->filter(fn (NextBestAction $action) => $action->priority !== ActionPriority::Low);

        $sourcing = $this->analytics->positionHealth($user)
            ->filter(fn (array $row) => $row['risk'] !== 'on_track' && $row['pipeline'] < $row['remaining'] * 2)
            ->map(fn (array $row) => new NextBestAction(
                'thin_pipeline',
                $row['risk'] === 'critical' ? ActionPriority::High : ActionPriority::Medium,
                RecruiterActionType::SourceCandidates,
                'Source more candidates for this position.',
                "{$row['pipeline']} active candidate(s) for {$row['remaining']} open position(s); open {$row['ageing_days']} days.",
                $row['requisition'],
                $row['requisition']->manager,
            ));

        $requisitionRisks = HiringRisk::query()
            ->open()
            ->whereNull('candidate_application_id')
            ->whereIn('severity', [RiskSeverity::High, RiskSeverity::Critical])
            ->whereIn('requisition_id', RecruitmentRequisition::query()->visibleTo($user)->select('id'))
            ->with(['evidence', 'requisition.manager'])
            ->limit(20)
            ->get()
            ->map(fn (HiringRisk $risk) => $this->fromRisk($risk, $risk->requisition, $risk->owner));

        return $recommendations->concat($sourcing)->concat($requisitionRisks)
            ->sortBy(fn (NextBestAction $action) => [$action->priority->rank(), $action->dueAt?->getTimestamp() ?? PHP_INT_MAX])
            ->take($limit)
            ->values();
    }

    private function stageDefault(CandidateApplication $application, bool $hasOpenWork): NextBestAction
    {
        $stage = $application->current_stage;

        [$action, $type, $tool] = match ($stage) {
            CandidateStage::Sourced, CandidateStage::ContactAttempted => ['Contact the candidate to establish a first conversation.', RecruiterActionType::FollowUp, 'create_followup'],
            CandidateStage::Connected => ['Confirm the candidate\'s interest in the role.', RecruiterActionType::FollowUp, 'move_candidates_stage'],
            CandidateStage::Interested => ['Screen the candidate against the requisition requirements.', RecruiterActionType::ReviewApplication, 'get_requisition'],
            CandidateStage::Screened => ['Decide whether to shortlist the candidate.', RecruiterActionType::ReviewApplication, 'move_candidates_stage'],
            CandidateStage::Shortlisted => ['Schedule the first interview round.', RecruiterActionType::Custom, 'schedule_interview'],
            CandidateStage::InterviewScheduled, CandidateStage::Interview1, CandidateStage::Interview2 => ['Schedule or complete the next interview round.', RecruiterActionType::Custom, 'schedule_interview'],
            CandidateStage::FinalInterview => ['Review all interview feedback and make a selection decision.', RecruiterActionType::CollectFeedback, 'summarize_interview_feedback'],
            CandidateStage::Selected => ['Initiate an offer.', RecruiterActionType::InitiateOffer, null],
            CandidateStage::OfferInitiated => ['Get the offer approved and released.', RecruiterActionType::InitiateOffer, null],
            CandidateStage::OfferReleased => ['Follow up for the candidate\'s offer decision.', RecruiterActionType::ChaseOfferDecision, 'create_followup'],
            CandidateStage::OfferAccepted => ['Confirm the joining date with the candidate.', RecruiterActionType::ConfirmJoining, 'create_followup'],
            CandidateStage::JoiningConfirmed => ['Track the joining date and pre-joining documents.', RecruiterActionType::ConfirmJoining, 'find_joining_risks'],
            CandidateStage::Joined => ['Collect the joining documents.', RecruiterActionType::Custom, null],
            CandidateStage::DocumentsCompleted => ['Complete onboarding.', RecruiterActionType::Custom, null],
            CandidateStage::OnboardingCompleted => ['No further recruitment action — onboarding is complete.', RecruiterActionType::Custom, null],
        };

        return new NextBestAction("stage:{$stage->value}", $hasOpenWork ? ActionPriority::Low : ActionPriority::Medium, $type, $action, "Current stage is {$stage->label()}.", $application, $application->recruiter, suggestedTool: $tool);
    }

    private function fromRisk(HiringRisk $risk, ?Model $entity, ?Employee $owner): NextBestAction
    {
        return new NextBestAction(
            'risk:'.$risk->type->value,
            $risk->severity->actionPriority(),
            match ($risk->type) {
                HiringRiskType::JoiningRisk => RecruiterActionType::ConfirmJoining,
                HiringRiskType::OfferRisk => RecruiterActionType::ChaseOfferDecision,
                HiringRiskType::ThinPipeline, HiringRiskType::SourcingStalled => RecruiterActionType::SourceCandidates,
                default => RecruiterActionType::Custom,
            },
            (string) $risk->recommended_action,
            "Risk Radar: {$risk->title}. {$risk->description}",
            $entity ?? $risk,
            $owner ?? $risk->owner,
            now(),
            null,
            $risk->evidence->take(3)->map(fn ($row) => trim($row->label.': '.$row->value))->values()->all(),
            $risk->evidence->first()?->subject_key,
        );
    }
}
