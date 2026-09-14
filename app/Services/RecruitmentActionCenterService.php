<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\FollowupStatus;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Enums\RequisitionStatus;
use App\Filament\Resources\CandidateApplications\CandidateApplicationResource;
use App\Filament\Resources\CandidateJoinings\CandidateJoiningResource;
use App\Filament\Resources\Interviews\InterviewResource;
use App\Filament\Resources\Offers\OfferResource;
use App\Filament\Resources\RecruitmentFollowups\RecruitmentFollowupResource;
use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\RecruitmentFollowup;
use App\Models\RecruitmentSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Turns real pending-work records into a single prioritized queue (never a static/fake to-do
 * list). Every row is a live count over an existing table, and carries a URL into the resource
 * that already manages that record type — the Action Center does not introduce a parallel view.
 * Thresholds come from RecruitmentSetting (offer_expiry_alert_days, vacancy_ageing_alert_days,
 * candidate_stall_days) rather than being hard-coded.
 */
class RecruitmentActionCenterService
{
    /**
     * Upcoming interviews starting within this many days that are still not confirmed.
     */
    public const int UNCONFIRMED_INTERVIEW_WINDOW_DAYS = 2;

    public function __construct(private readonly HierarchyService $hierarchy) {}

    /**
     * Critical items first, then attention items, each group keeping its declared order.
     *
     * @return Collection<int, array{key: string, label: string, priority: string, count: int, url: string|null}>
     */
    public function pendingWork(?User $user = null): Collection
    {
        $visibleIds = $user !== null ? $this->hierarchy->visibleEmployeeIdsFor($user) : null;

        $items = collect([
            $this->overdueFollowups($visibleIds),
            $this->interviewsMissingFeedback($visibleIds),
            $this->unconfirmedInterviews($visibleIds),
            $this->selectedWithoutOffer($visibleIds),
            $this->offersExpiringSoon($visibleIds),
            $this->joiningDatePassedNotJoined($visibleIds),
            $this->acceptedWithoutJoiningConfirmation($visibleIds),
            $this->offerAcceptancePending($visibleIds),
            $this->followupsDueToday($visibleIds),
            $this->ageingRequisitions($user),
            $this->stalledCandidates($visibleIds),
        ]);

        return $items
            ->filter(fn (array $item) => $item['count'] > 0)
            ->sortBy(fn (array $item) => $item['priority'] === 'critical' ? 0 : 1)
            ->values();
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
            'url' => RecruitmentFollowupResource::getUrl('index', ['filters' => ['overdue' => ['value' => true]]]),
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
            'url' => InterviewResource::getUrl('index', ['filters' => ['status' => ['value' => InterviewStatus::Completed->value]]]),
        ];
    }

    /**
     * Upcoming Scheduled/Rescheduled interviews (not yet Confirmed) starting within the next
     * UNCONFIRMED_INTERVIEW_WINDOW_DAYS days — the candidate may not turn up if nobody confirms.
     *
     * @param  Collection<int, int>|null  $visibleIds
     * @return array{key: string, label: string, priority: string, count: int, url: string|null}
     */
    private function unconfirmedInterviews(?Collection $visibleIds): array
    {
        $count = Interview::query()
            ->whereIn('status', InterviewStatus::unconfirmed())
            ->whereBetween('scheduled_at', [now(), now()->addDays(self::UNCONFIRMED_INTERVIEW_WINDOW_DAYS)])
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)))
            ->count();

        return [
            'key' => 'unconfirmed_interviews',
            'label' => 'Upcoming interviews not yet confirmed',
            'priority' => 'critical',
            'count' => $count,
            'url' => InterviewResource::getUrl('index', ['filters' => ['status' => ['value' => InterviewStatus::Scheduled->value]]]),
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
            'url' => CandidateApplicationResource::getUrl('index', ['filters' => ['current_stage' => ['value' => 'selected']]]),
        ];
    }

    /**
     * Released offers whose validity (offer_expiry) ends within `offer_expiry_alert_days`.
     *
     * @param  Collection<int, int>|null  $visibleIds
     * @return array{key: string, label: string, priority: string, count: int, url: string|null}
     */
    private function offersExpiringSoon(?Collection $visibleIds): array
    {
        $alertDays = (int) RecruitmentSetting::get('offer_expiry_alert_days', 3);

        $count = Offer::query()
            ->where('status', OfferStatus::Released)
            ->whereNotNull('offer_expiry')
            ->whereBetween('offer_expiry', [now()->toDateString(), now()->addDays($alertDays)->toDateString()])
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)))
            ->count();

        return [
            'key' => 'offers_expiring',
            'label' => "Offers expiring within {$alertDays} days",
            'priority' => 'critical',
            'count' => $count,
            'url' => OfferResource::getUrl('index', ['filters' => ['status' => ['value' => OfferStatus::Released->value]]]),
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
            'url' => CandidateJoiningResource::getUrl('index', ['filters' => ['status' => ['value' => JoiningStatus::Expected->value]]]),
        ];
    }

    /**
     * The joinings list has no "date passed" filter option yet, so the link filters to Expected
     * (the most common overdue status); the list's default expected_doj ascending sort then puts
     * the oldest overdue joiners first.
     *
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
            'url' => CandidateJoiningResource::getUrl('index', ['filters' => ['status' => ['value' => JoiningStatus::Expected->value]]]),
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
            'priority' => 'attention',
            'count' => $count,
            'url' => OfferResource::getUrl('index', ['filters' => ['status' => ['value' => OfferStatus::Released->value]]]),
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
            ->whereBetween('followup_date', [now(), now()->endOfDay()])
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds))
            ->count();

        return [
            'key' => 'followups_due_today',
            'label' => 'Candidates requiring follow-up today',
            'priority' => 'attention',
            'count' => $count,
            'url' => RecruitmentFollowupResource::getUrl('index', ['filters' => ['status' => ['value' => FollowupStatus::Pending->value]]]),
        ];
    }

    /**
     * Open requisitions past `vacancy_ageing_alert_days` — reuses RecruitmentAnalyticsService's
     * vacancyAgeing() (and its multi-column requisition hierarchy scope) rather than re-deriving
     * ageing here.
     *
     * @return array{key: string, label: string, priority: string, count: int, url: string|null}
     */
    private function ageingRequisitions(?User $user): array
    {
        $count = app(RecruitmentAnalyticsService::class)->vacancyAgeing($user)
            ->filter(fn (array $row) => $row['is_overdue'] && $row['requisition']->status === RequisitionStatus::Open)
            ->count();

        return [
            'key' => 'ageing_requisitions',
            'label' => 'Open requisitions past the ageing threshold',
            'priority' => 'attention',
            'count' => $count,
            'url' => RecruitmentRequisitionResource::getUrl('index', ['filters' => ['status' => ['value' => RequisitionStatus::Open->value]]]),
        ];
    }

    /**
     * Active, not-yet-joined applications with no stage change (candidate_stage_histories row) for
     * `candidate_stall_days`. Applications younger than the window are never "stalled".
     *
     * @param  Collection<int, int>|null  $visibleIds
     * @return array{key: string, label: string, priority: string, count: int, url: string|null}
     */
    private function stalledCandidates(?Collection $visibleIds): array
    {
        $stallDays = (int) RecruitmentSetting::get('candidate_stall_days', 7);
        $threshold = now()->subDays($stallDays);

        $count = CandidateApplication::query()
            ->where('status', ApplicationStatus::Active)
            ->where('current_stage', '!=', CandidateStage::Joined)
            ->where('created_at', '<', $threshold)
            ->whereDoesntHave('stageHistory', fn (Builder $q) => $q->where('created_at', '>=', $threshold))
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds))
            ->count();

        return [
            'key' => 'stalled_candidates',
            'label' => "Candidates with no stage movement for {$stallDays}+ days",
            'priority' => 'attention',
            'count' => $count,
            'url' => CandidateApplicationResource::getUrl('index', ['filters' => ['status' => ['value' => ApplicationStatus::Active->value]]]),
        ];
    }
}
