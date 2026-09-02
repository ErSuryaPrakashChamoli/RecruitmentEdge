<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\FollowupStatus;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Filament\Resources\CandidateApplications\CandidateApplicationResource;
use App\Filament\Resources\CandidateJoinings\CandidateJoiningResource;
use App\Filament\Resources\Interviews\InterviewResource;
use App\Filament\Resources\Offers\OfferResource;
use App\Filament\Resources\RecruitmentFollowups\RecruitmentFollowupResource;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\RecruitmentFollowup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Turns real pending-work records into a single prioritized queue (never a static/fake to-do
 * list). Every row is a live count over an existing table, and carries a URL into the resource
 * that already manages that record type — the Action Center does not introduce a parallel view.
 */
class RecruitmentActionCenterService
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    /**
     * @return Collection<int, array{key: string, label: string, priority: string, count: int, url: string|null}>
     */
    public function pendingWork(?User $user = null): Collection
    {
        $visibleIds = $user !== null ? $this->hierarchy->visibleEmployeeIdsFor($user) : null;

        $items = collect([
            $this->overdueFollowups($visibleIds),
            $this->interviewsMissingFeedback($visibleIds),
            $this->selectedWithoutOffer($visibleIds),
            $this->acceptedWithoutJoiningConfirmation($visibleIds),
            $this->joiningDatePassedNotJoined($visibleIds),
            $this->offerAcceptancePending($visibleIds),
            $this->followupsDueToday($visibleIds),
        ]);

        return $items->filter(fn (array $item) => $item['count'] > 0)->values();
    }

    /**
     * @return Collection<int, array{key: string, label: string, severity: string, message: string}>
     */
    public function alerts(?User $user = null): Collection
    {
        $visibleIds = $user !== null ? $this->hierarchy->visibleEmployeeIdsFor($user) : null;
        $alerts = collect();

        $thisWeek = app(RecruitmentAnalyticsService::class)->turnUpAnalysis(now()->subDays(6), now(), $user);
        $lastWeek = app(RecruitmentAnalyticsService::class)->turnUpAnalysis(now()->subDays(13), now()->subDays(7), $user);

        if ($thisWeek['turnup_percent'] !== null && $lastWeek['turnup_percent'] !== null
            && $thisWeek['turnup_percent'] < $lastWeek['turnup_percent'] - 5) {
            $alerts->push([
                'key' => 'turnup_drop',
                'label' => 'Turn-up ratio dropped',
                'severity' => 'warning',
                'message' => "Turn-up ratio dropped from {$lastWeek['turnup_percent']}% to {$thisWeek['turnup_percent']}% this week.",
            ]);
        }

        $positionsAtRisk = app(RecruitmentAnalyticsService::class)->positionHealth($user)
            ->whereIn('risk', ['critical', 'at_risk']);

        if ($positionsAtRisk->isNotEmpty()) {
            $alerts->push([
                'key' => 'positions_at_risk',
                'label' => 'Positions at risk',
                'severity' => $positionsAtRisk->contains('risk', 'critical') ? 'critical' : 'warning',
                'message' => "{$positionsAtRisk->count()} position(s) are below required pipeline or overdue on ageing.",
            ]);
        }

        $offersAwaiting = Offer::query()
            ->where('status', OfferStatus::Released)
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)))
            ->count();

        if ($offersAwaiting > 0) {
            $alerts->push([
                'key' => 'offers_awaiting_acceptance',
                'label' => 'Offers awaiting acceptance',
                'severity' => 'warning',
                'message' => "{$offersAwaiting} offer(s) awaiting candidate acceptance.",
            ]);
        }

        $joiningRisks = app(RecruitmentAnalyticsService::class)->joiningRisks($user);
        $approaching = $joiningRisks->where('risk', 'yellow')->count();

        if ($approaching > 0) {
            $alerts->push([
                'key' => 'joining_dates_approaching',
                'label' => 'Joining dates approaching',
                'severity' => 'warning',
                'message' => "{$approaching} joining date(s) are approaching without confirmation.",
            ]);
        }

        return $alerts;
    }

    /**
     * @param  Collection<int, int>|null  $visibleIds
     * @return array{key: string, label: string, priority: string, count: int, url: string|null}
     */
    private function overdueFollowups(?Collection $visibleIds): array
    {
        $count = RecruitmentFollowup::query()
            ->where('status', FollowupStatus::Pending)
            ->where('followup_date', '<', now())
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds))
            ->count();

        return [
            'key' => 'overdue_followups',
            'label' => 'Follow-ups overdue',
            'priority' => 'critical',
            'count' => $count,
            'url' => RecruitmentFollowupResource::getUrl('index', ['tableFilters' => ['status' => ['value' => FollowupStatus::Pending->value]]]),
        ];
    }

    /**
     * @param  Collection<int, int>|null  $visibleIds
     * @return array{key: string, label: string, priority: string, count: int, url: string|null}
     */
    private function interviewsMissingFeedback(?Collection $visibleIds): array
    {
        $count = Interview::query()
            ->where('status', InterviewStatus::Completed)
            ->whereNull('result')
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)))
            ->count();

        return [
            'key' => 'interview_feedback_pending',
            'label' => 'Interview feedback pending',
            'priority' => 'critical',
            'count' => $count,
            'url' => InterviewResource::getUrl('index', ['tableFilters' => ['status' => ['value' => InterviewStatus::Completed->value]]]),
        ];
    }

    /**
     * @param  Collection<int, int>|null  $visibleIds
     * @return array{key: string, label: string, priority: string, count: int, url: string|null}
     */
    private function selectedWithoutOffer(?Collection $visibleIds): array
    {
        $count = CandidateApplication::query()
            ->where('current_stage', 'selected')
            ->where('status', ApplicationStatus::Active)
            ->whereDoesntHave('offers')
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds))
            ->count();

        return [
            'key' => 'selected_without_offer',
            'label' => 'Selected candidates without an offer',
            'priority' => 'critical',
            'count' => $count,
            'url' => CandidateApplicationResource::getUrl('index', ['tableFilters' => ['current_stage' => ['value' => 'selected']]]),
        ];
    }

    /**
     * @param  Collection<int, int>|null  $visibleIds
     * @return array{key: string, label: string, priority: string, count: int, url: string|null}
     */
    private function acceptedWithoutJoiningConfirmation(?Collection $visibleIds): array
    {
        $count = Offer::query()
            ->where('status', OfferStatus::Accepted)
            ->whereDoesntHave('joining', fn (Builder $q) => $q->where('status', '!=', JoiningStatus::Expected->value))
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)))
            ->count();

        return [
            'key' => 'joining_confirmation_pending',
            'label' => 'Selected candidates requiring joining confirmation',
            'priority' => 'attention',
            'count' => $count,
            'url' => CandidateJoiningResource::getUrl('index', ['tableFilters' => ['status' => ['value' => JoiningStatus::Expected->value]]]),
        ];
    }

    /**
     * @param  Collection<int, int>|null  $visibleIds
     * @return array{key: string, label: string, priority: string, count: int, url: string|null}
     */
    private function joiningDatePassedNotJoined(?Collection $visibleIds): array
    {
        $count = CandidateJoining::query()
            ->whereIn('status', [JoiningStatus::Expected, JoiningStatus::Confirmed])
            ->where('expected_doj', '<', now()->startOfDay())
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)))
            ->count();

        return [
            'key' => 'joining_date_passed',
            'label' => 'Joining date passed, not yet joined',
            'priority' => 'critical',
            'count' => $count,
            'url' => CandidateJoiningResource::getUrl('index'),
        ];
    }

    /**
     * @param  Collection<int, int>|null  $visibleIds
     * @return array{key: string, label: string, priority: string, count: int, url: string|null}
     */
    private function offerAcceptancePending(?Collection $visibleIds): array
    {
        $count = Offer::query()
            ->where('status', OfferStatus::Released)
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)))
            ->count();

        return [
            'key' => 'offer_acceptance_pending',
            'label' => 'Offer acceptance pending',
            'priority' => 'critical',
            'count' => $count,
            'url' => OfferResource::getUrl('index', ['tableFilters' => ['status' => ['value' => OfferStatus::Released->value]]]),
        ];
    }

    /**
     * @param  Collection<int, int>|null  $visibleIds
     * @return array{key: string, label: string, priority: string, count: int, url: string|null}
     */
    private function followupsDueToday(?Collection $visibleIds): array
    {
        $count = RecruitmentFollowup::query()
            ->where('status', FollowupStatus::Pending)
            ->whereBetween('followup_date', [now()->startOfDay(), now()->endOfDay()])
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds))
            ->count();

        return [
            'key' => 'followups_due_today',
            'label' => 'Candidates requiring follow-up today',
            'priority' => 'attention',
            'count' => $count,
            'url' => RecruitmentFollowupResource::getUrl('index', ['tableFilters' => ['status' => ['value' => FollowupStatus::Pending->value]]]),
        ];
    }
}
