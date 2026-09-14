<?php

namespace App\Services\AI\Tools\CandidateTools;

use App\Enums\AiRiskLevel;
use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\FollowupStatus;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Models\CandidateApplication;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\RecruitmentFollowup;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;

/**
 * RECOMMEND risk, advisory only: derives the next best action for one application (or a
 * candidate's most recent visible active application) from its status, stage, overdue follow-ups,
 * interviews awaiting confirmation/feedback, open offers, and joining state. Nothing is written —
 * each recommendation names the tool/screen the user would use to act on it, so any change still
 * goes through the normal confirmation-gated action tools.
 */
class RecommendNextStepTool implements AiTool
{
    use ScopesToHierarchy;

    private const int STALE_AFTER_DAYS = 7;

    public function name(): string
    {
        return 'recommend_next_step';
    }

    public function description(): string
    {
        return 'Recommend the next best action (advisory, nothing is changed) for a candidate application — or a candidate\'s latest active application — based on its stage, status, pending interviews/feedback, offers, joining, and overdue follow-ups.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'application_id' => ['type' => 'integer'],
                'candidate_id' => ['type' => 'integer', 'description' => 'Used when no application_id is given: picks the candidate\'s most recent visible active application'],
            ],
        ];
    }

    public function riskLevel(): AiRiskLevel
    {
        return AiRiskLevel::Recommend;
    }

    public function permission(): ?string
    {
        return 'candidates.viewAny';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        $application = $this->resolveApplication($arguments, $user);

        if ($application === null) {
            return ToolResult::fail('Application not found, or not visible to you. Pass an application_id or a candidate_id.');
        }

        $signals = $this->signalsFor($application);
        $primary = $signals[0];

        return ToolResult::ok(
            data: [
                'application_id' => $application->id,
                'candidate' => $application->candidate?->full_name,
                'stage' => $application->current_stage->label(),
                'status' => $application->status->label(),
                'days_since_last_activity' => $application->last_activity_at !== null ? (int) $application->last_activity_at->diffInDays(now()) : null,
                'recommended_action' => $primary,
                'other_signals' => array_slice($signals, 1),
                'advisory' => true,
            ],
            summary: "Next step for {$application->candidate?->full_name}: {$primary['action']}",
            type: 'recommendation',
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function resolveApplication(array $arguments, User $user): ?CandidateApplication
    {
        $query = $this->scopeRecruiterOwnedTo(CandidateApplication::query(), $user)
            ->with([
                'candidate:id,full_name',
                'joining',
                'interviews.feedback',
                'offers',
                'followups' => fn ($followups) => $followups->where('status', FollowupStatus::Pending),
            ]);

        if (filled($arguments['application_id'] ?? null)) {
            return $query->find($arguments['application_id']);
        }

        if (blank($arguments['candidate_id'] ?? null)) {
            return null;
        }

        return $query
            ->where('candidate_id', $arguments['candidate_id'])
            ->orderByRaw('case when status = ? then 0 else 1 end', [ApplicationStatus::Active->value])
            ->latest('application_date')
            ->latest('id')
            ->first();
    }

    /**
     * Ordered most urgent first; always contains at least one entry.
     *
     * @return array<int, array{priority: string, action: string, reason: string, suggested_tool: string|null}>
     */
    private function signalsFor(CandidateApplication $application): array
    {
        if (in_array($application->status, [ApplicationStatus::Rejected, ApplicationStatus::Dropout], true)) {
            return [$this->signal('low', 'No action needed — this application is closed.', "Status is {$application->status->label()} at the {$application->current_stage->label()} stage.")];
        }

        $signals = [];

        if ($application->status === ApplicationStatus::OnHold) {
            $signals[] = $this->signal('medium', 'Review the hold and decide whether to resume or close this application.', 'The application is on hold.', 'move_candidates_stage');
        }

        $overdueFollowups = $application->followups->filter(fn (RecruitmentFollowup $followup) => $followup->isOverdue());

        if ($overdueFollowups->isNotEmpty()) {
            $oldest = $overdueFollowups->sortBy('followup_date')->first();
            $signals[] = $this->signal('high', "Complete the overdue {$oldest->followup_type->label()} follow-up.", "{$overdueFollowups->count()} follow-up(s) overdue, oldest due {$oldest->followup_date->toDayDateTimeString()}.", 'list_overdue_followups');
        }

        $interviews = $application->interviews;

        $awaitingFeedback = $interviews->first(fn (Interview $interview) => $interview->status === InterviewStatus::Completed && $interview->result === null);

        if ($awaitingFeedback !== null) {
            $signals[] = $this->signal('high', "Collect feedback and record a result for interview round {$awaitingFeedback->round_number}.", 'The interview is completed but has no result yet.', 'summarize_interview_feedback');
        }

        $unconfirmedPast = $interviews->first(fn (Interview $interview) => $interview->status->awaitsConfirmation() && $interview->scheduled_at?->isPast());

        if ($unconfirmedPast !== null) {
            $signals[] = $this->signal('high', "Update interview round {$unconfirmedPast->round_number}: it was scheduled for {$unconfirmedPast->scheduled_at->toDayDateTimeString()} but never confirmed or completed.", 'Interview time has passed without a confirmation or outcome.', 'search_interviews');
        }

        $upcoming = $interviews
            ->filter(fn (Interview $interview) => ! $interview->status->isTerminal() && $interview->scheduled_at?->isFuture())
            ->sortBy('scheduled_at')
            ->first();

        if ($upcoming !== null) {
            $signals[] = $this->signal(
                $upcoming->status->awaitsConfirmation() ? 'medium' : 'low',
                $upcoming->status->awaitsConfirmation()
                    ? "Confirm interview round {$upcoming->round_number} with the candidate and interviewer."
                    : "Prepare for interview round {$upcoming->round_number} on {$upcoming->scheduled_at->toDayDateTimeString()}.",
                "Interview is {$upcoming->status->label()}.",
                'generate_interview_questions',
            );
        }

        $openOffer = $application->offers->first(fn (Offer $offer) => in_array($offer->status, [OfferStatus::Initiated, OfferStatus::Released], true));

        if ($openOffer !== null) {
            $expired = $openOffer->offer_expiry !== null && $openOffer->offer_expiry->endOfDay()->isPast();

            $signals[] = $expired
                ? $this->signal('high', 'The offer validity has lapsed — follow up for a decision, or extend/withdraw the offer.', "Offer {$openOffer->offer_code} expired on {$openOffer->offer_expiry->toDateString()}.", 'create_followup')
                : $this->signal('medium', $openOffer->status === OfferStatus::Initiated ? 'Release the initiated offer.' : 'Chase the candidate for an offer decision.', "Offer {$openOffer->offer_code} is {$openOffer->status->label()}".($openOffer->offer_expiry !== null ? ", valid until {$openOffer->offer_expiry->toDateString()}." : '.'), 'create_followup');
        }

        $joining = $application->joining;

        if ($joining !== null && ! in_array($joining->status, [JoiningStatus::Joined, JoiningStatus::NoShow, JoiningStatus::Dropout], true)) {
            $risk = $joining->riskLevel();

            if (in_array($risk, ['yellow', 'red'], true)) {
                $signals[] = $this->signal($risk === 'red' ? 'high' : 'medium', 'Call the candidate to reconfirm their joining date.', "Joining is {$joining->status->label()} with {$risk} risk (expected {$joining->expected_doj?->toDateString()}).", 'find_joining_risks');
            }
        }

        if ($application->status === ApplicationStatus::Active && $application->last_activity_at !== null && $application->last_activity_at->lte(now()->subDays(self::STALE_AFTER_DAYS))) {
            $signals[] = $this->signal('medium', 'Re-engage the candidate — there has been no activity for over a week.', 'Last activity '.$application->last_activity_at->diffForHumans().'.', 'create_followup');
        }

        $signals[] = $this->stageDefault($application, $upcoming !== null || $openOffer !== null);

        $order = ['high' => 0, 'medium' => 1, 'low' => 2];

        return collect($signals)
            ->sortBy(fn (array $signal, int $index) => [$order[$signal['priority']], $index])
            ->values()
            ->all();
    }

    /**
     * @return array{priority: string, action: string, reason: string, suggested_tool: string|null}
     */
    private function stageDefault(CandidateApplication $application, bool $hasOpenWork): array
    {
        $stage = $application->current_stage;
        $reason = "Current stage is {$stage->label()}.";

        [$action, $tool] = match ($stage) {
            CandidateStage::Sourced, CandidateStage::ContactAttempted => ['Contact the candidate to establish a first conversation.', 'create_followup'],
            CandidateStage::Connected => ['Confirm the candidate\'s interest in the role.', 'move_candidates_stage'],
            CandidateStage::Interested => ['Screen the candidate against the requisition requirements.', 'get_requisition'],
            CandidateStage::Screened => ['Decide whether to shortlist the candidate.', 'move_candidates_stage'],
            CandidateStage::Shortlisted => ['Schedule the first interview round.', 'schedule_interview'],
            CandidateStage::InterviewScheduled, CandidateStage::Interview1, CandidateStage::Interview2 => ['Schedule or complete the next interview round.', 'schedule_interview'],
            CandidateStage::FinalInterview => ['Review all interview feedback and make a selection decision.', 'summarize_interview_feedback'],
            CandidateStage::Selected => ['Initiate an offer.', null],
            CandidateStage::OfferInitiated => ['Get the offer approved and released.', null],
            CandidateStage::OfferReleased => ['Follow up for the candidate\'s offer decision.', 'create_followup'],
            CandidateStage::OfferAccepted => ['Confirm the joining date with the candidate.', 'create_followup'],
            CandidateStage::JoiningConfirmed => ['Track the joining date and pre-joining documents.', 'find_joining_risks'],
            CandidateStage::Joined => ['Collect the joining documents.', null],
            CandidateStage::DocumentsCompleted => ['Complete onboarding.', null],
            CandidateStage::OnboardingCompleted => ['No further recruitment action — onboarding is complete.', null],
        };

        return $this->signal($hasOpenWork ? 'low' : 'medium', $action, $reason, $tool);
    }

    /**
     * @return array{priority: string, action: string, reason: string, suggested_tool: string|null}
     */
    private function signal(string $priority, string $action, string $reason, ?string $suggestedTool = null): array
    {
        return ['priority' => $priority, 'action' => $action, 'reason' => $reason, 'suggested_tool' => $suggestedTool];
    }
}
