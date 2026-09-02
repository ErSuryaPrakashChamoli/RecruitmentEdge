<?php

namespace App\Filament\Widgets;

use App\Enums\CandidateStage;
use App\Enums\JoiningStatus;
use App\Filament\Resources\CandidateApplications\CandidateApplicationResource;
use App\Models\CandidateJoining;
use App\Models\RecruitmentDailyActivity;
use App\Models\RecruitmentFollowup;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\RecruitmentAnalyticsService;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The Joining Tracker executive control center (Stage 4, Module 4) — summary cards, a Selected ->
 * ... -> Joined pipeline, a green/amber/red risk panel, and a "Joining Tomorrow" action panel.
 * Every number reuses an existing method: RecruitmentAnalyticsService::joiningAnalytics()/funnel()
 * and CandidateJoining::riskLevel() (unfiltered here, unlike joiningRisks() which drops green) —
 * this widget only assembles and presents, it computes nothing new.
 */
class JoiningControlCenterWidget extends Widget
{
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.joining-control-center';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array{today: int, tomorrow: int, this_week: int, confirmed: int, needs_followup: int, high_risk: int, no_show: int, joined: int}
     */
    public function getSummary(): array
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);
        $analytics = app(RecruitmentAnalyticsService::class)->joiningAnalytics(now()->startOfMonth(), now()->endOfMonth(), $user);

        $active = $this->activeJoinings($visibleIds);
        $byRisk = $active->groupBy(fn (CandidateJoining $j) => $j->riskLevel());

        return [
            'today' => $analytics['today'],
            'tomorrow' => $analytics['tomorrow'],
            'this_week' => $analytics['next_7_days'],
            'confirmed' => $byRisk->get('green', collect())->count(),
            'needs_followup' => $byRisk->get('yellow', collect())->count(),
            'high_risk' => $byRisk->get('red', collect())->count(),
            'no_show' => $analytics['no_show'],
            'joined' => $analytics['joined'],
        ];
    }

    /**
     * @return array<int, array{label: string, count: int}>
     */
    public function getPipeline(): array
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $analytics = app(RecruitmentAnalyticsService::class);
        $funnel = $analytics->funnel(now()->startOfMonth(), now()->endOfMonth(), $user)->keyBy(fn (array $row) => $row['stage']->value);
        $joiningAnalytics = $analytics->joiningAnalytics(now()->startOfMonth(), now()->endOfMonth(), $user);

        return [
            ['label' => 'Selected', 'count' => $funnel->get(CandidateStage::Selected->value)['count'] ?? 0],
            ['label' => 'Offer Released', 'count' => $funnel->get(CandidateStage::OfferReleased->value)['count'] ?? 0],
            ['label' => 'Offer Accepted', 'count' => $funnel->get(CandidateStage::OfferAccepted->value)['count'] ?? 0],
            ['label' => 'Joining Confirmed', 'count' => $funnel->get(CandidateStage::JoiningConfirmed->value)['count'] ?? 0],
            ['label' => 'Joining Today', 'count' => $joiningAnalytics['today']],
            ['label' => 'Joined', 'count' => $funnel->get(CandidateStage::Joined->value)['count'] ?? 0],
        ];
    }

    /**
     * @return array{green: Collection<int, CandidateJoining>, yellow: Collection<int, CandidateJoining>, red: Collection<int, CandidateJoining>}
     */
    public function getRiskGroups(): array
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);
        $byRisk = $this->activeJoinings($visibleIds)->groupBy(fn (CandidateJoining $j) => $j->riskLevel());

        return [
            'green' => $byRisk->get('green', collect())->take(5)->values(),
            'yellow' => $byRisk->get('yellow', collect())->take(5)->values(),
            'red' => $byRisk->get('red', collect())->take(5)->values(),
        ];
    }

    /**
     * @return Collection<int, array{joining: CandidateJoining, lastContact: Carbon|null}>
     */
    public function getTomorrowJoinings(): Collection
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);

        $joinings = CandidateJoining::query()
            ->whereIn('status', [JoiningStatus::Expected, JoiningStatus::Confirmed])
            ->whereDate('expected_doj', now()->addDay()->toDateString())
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)))
            ->with(['candidateApplication.candidate', 'candidateApplication.requisition.designation', 'candidateApplication.recruiter'])
            ->get();

        if ($joinings->isEmpty()) {
            return collect();
        }

        $applicationIds = $joinings->pluck('candidateApplication.id');

        $lastActivity = RecruitmentDailyActivity::query()
            ->whereIn('candidate_application_id', $applicationIds)
            ->orderByDesc('activity_datetime')
            ->get(['candidate_application_id', 'activity_datetime'])
            ->unique('candidate_application_id')
            ->keyBy('candidate_application_id');

        $lastFollowup = RecruitmentFollowup::query()
            ->whereIn('candidate_application_id', $applicationIds)
            ->orderByDesc('followup_date')
            ->get(['candidate_application_id', 'followup_date'])
            ->unique('candidate_application_id')
            ->keyBy('candidate_application_id');

        return $joinings->map(function (CandidateJoining $joining) use ($lastActivity, $lastFollowup) {
            $appId = $joining->candidateApplication->id;
            $activityAt = $lastActivity->get($appId)?->activity_datetime;
            $followupAt = $lastFollowup->get($appId)?->followup_date;
            $lastContact = collect([$activityAt, $followupAt])->filter()->sortDesc()->first();

            return ['joining' => $joining, 'lastContact' => $lastContact];
        });
    }

    public function candidateUrl(CandidateJoining $joining): string
    {
        return CandidateApplicationResource::getUrl('view', ['record' => $joining->candidateApplication]);
    }

    /**
     * @param  Collection<int, int>|null  $visibleIds
     * @return Collection<int, CandidateJoining>
     */
    private function activeJoinings(?Collection $visibleIds): Collection
    {
        return CandidateJoining::query()
            ->whereIn('status', [JoiningStatus::Expected, JoiningStatus::Confirmed])
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)))
            ->with(['candidateApplication.candidate', 'candidateApplication.requisition.designation'])
            ->get();
    }
}
