<?php

namespace App\Filament\Widgets;

use App\Enums\ActivityType;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Enums\RequisitionStatus;
use App\Models\Candidate;
use App\Models\CandidateJoining;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\RecruitmentDailyActivity;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\CostPerHireService;
use App\Services\HierarchyService;
use App\Services\RecruitmentAnalyticsService;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Section 5's headline numbers, hierarchy-scoped to the viewer's team like every other widget in
 * the app.
 */
class RecruitmentOverviewStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);

        $today = [now()->startOfDay(), now()->endOfDay()];
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $openPositions = RecruitmentRequisition::query()
            ->where('status', RequisitionStatus::Open)
            ->when($visibleIds !== null, fn (Builder $q) => $q->where(function (Builder $q2) use ($visibleIds): void {
                $q2->whereIn('manager_id', $visibleIds)
                    ->orWhereIn('assistant_manager_id', $visibleIds)
                    ->orWhereIn('vp_hr_id', $visibleIds)
                    ->orWhereIn('created_by', $visibleIds)
                    ->orWhereHas('recruiters', fn (Builder $r) => $r->whereIn('employees.id', $visibleIds));
            }))
            ->count();

        $filledThisMonth = CandidateJoining::query()
            ->where('status', JoiningStatus::Joined)
            ->whereBetween('actual_doj', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)))
            ->count();

        $sourcedToday = Candidate::query()
            ->whereBetween('created_at', $today)
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('created_by', $visibleIds))
            ->count();

        $callsToday = RecruitmentDailyActivity::query()
            ->where('activity_type', ActivityType::Call)
            ->whereBetween('activity_datetime', $today)
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds))
            ->count();

        $interviewsCompletedToday = Interview::query()
            ->where('status', InterviewStatus::Completed)
            ->whereBetween('scheduled_at', $today)
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)))
            ->count();

        $offersAcceptedMtd = Offer::query()
            ->where('status', OfferStatus::Accepted)
            ->whereBetween('accepted_at', [$monthStart, $monthEnd])
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)))
            ->count();

        $avgTimeToHire = app(RecruitmentAnalyticsService::class)->averageTimeToHireDays($monthStart, $monthEnd, $user);
        $costPerHire = app(CostPerHireService::class)->costPerHire($monthStart, $monthEnd);

        return [
            Stat::make('Open Positions', $openPositions),
            Stat::make('Filled This Month', $filledThisMonth),
            Stat::make('Candidates Sourced Today', $sourcedToday),
            Stat::make('Calls Today', $callsToday),
            Stat::make('Interviews Completed Today', $interviewsCompletedToday),
            Stat::make('Offers Accepted (MTD)', $offersAcceptedMtd),
            Stat::make('Avg. Time to Hire', $avgTimeToHire !== null ? "{$avgTimeToHire} days" : '—'),
            Stat::make('Cost per Hire (MTD)', $costPerHire !== null ? '₹'.number_format($costPerHire, 2) : '—'),
        ];
    }
}
