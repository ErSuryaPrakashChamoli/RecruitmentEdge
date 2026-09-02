<?php

namespace App\Filament\Widgets;

use App\Enums\IncentiveCalculationStatus;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\User;
use App\Services\HierarchyService;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Section 29's incentive aggregates for the current month, hierarchy-scoped like every other
 * widget. "Earned" sums every calculation not Rejected/Reversed, using effectiveAmount() so
 * corrections/adjustments are reflected — never the raw `amount` column alone (Section 28).
 */
class IncentiveDashboardStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);

        $calculations = RecruiterIncentiveCalculation::query()
            ->whereDate('period_start', now()->startOfMonth())
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('employee_id', $visibleIds))
            ->with('adjustments')
            ->get();

        $sumFor = fn (IncentiveCalculationStatus $status): float => $calculations
            ->where('status', $status)
            ->sum(fn (RecruiterIncentiveCalculation $c) => $c->effectiveAmount());

        $totalEarned = $calculations
            ->whereNotIn('status', [IncentiveCalculationStatus::Rejected, IncentiveCalculationStatus::Reversed])
            ->sum(fn (RecruiterIncentiveCalculation $c) => $c->effectiveAmount());

        return [
            Stat::make('Total Earned (This Month)', '₹'.number_format($totalEarned, 2)),
            Stat::make('Under Review', '₹'.number_format($sumFor(IncentiveCalculationStatus::Calculated), 2)),
            Stat::make('Pending Verification', '₹'.number_format($sumFor(IncentiveCalculationStatus::PendingVerification), 2)),
            Stat::make('Approved', '₹'.number_format($sumFor(IncentiveCalculationStatus::Approved), 2)),
            Stat::make('Payable', '₹'.number_format($sumFor(IncentiveCalculationStatus::Payable), 2)),
            Stat::make('Paid', '₹'.number_format($sumFor(IncentiveCalculationStatus::Paid), 2)),
        ];
    }
}
