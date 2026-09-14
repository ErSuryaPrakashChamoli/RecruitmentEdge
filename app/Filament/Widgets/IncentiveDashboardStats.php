<?php

namespace App\Filament\Widgets;

use App\Enums\IncentiveCalculationStatus;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\User;
use App\Services\HierarchyService;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;

/**
 * Section 29's incentive aggregates for the current month. The "My" stats are always the viewer's
 * OWN calculations (their scorecard); viewers who manage more than themselves additionally get a
 * hierarchy-scoped "Team" row. Amounts use effectiveAmount() so corrections/adjustments are
 * reflected — never the raw `amount` column alone (Section 28).
 */
class IncentiveDashboardStats extends StatsOverviewWidget
{
    /**
     * @var array<int, IncentiveCalculationStatus>
     */
    private const array SCORECARD_STATUSES = [
        IncentiveCalculationStatus::Calculated,
        IncentiveCalculationStatus::PendingVerification,
        IncentiveCalculationStatus::Approved,
        IncentiveCalculationStatus::Payable,
        IncentiveCalculationStatus::Paid,
    ];

    protected function getStats(): array
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        $own = $user->employee_id === null
            ? collect()
            : $this->currentMonthCalculations(collect([$user->employee_id]));

        $stats = $this->statsFor($own, 'My', 'Your own calculations this month');

        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);

        if ($visibleIds === null || $visibleIds->count() > 1) {
            $stats = [
                ...$stats,
                ...$this->statsFor($this->currentMonthCalculations($visibleIds), 'Team', 'Everyone in your visible hierarchy'),
            ];
        }

        return $stats;
    }

    /**
     * @param  Collection<int, int>|null  $employeeIds  null means unrestricted
     * @return Collection<int, RecruiterIncentiveCalculation>
     */
    private function currentMonthCalculations(?Collection $employeeIds): Collection
    {
        return RecruiterIncentiveCalculation::query()
            ->whereDate('period_start', now()->startOfMonth())
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('employee_id', $employeeIds))
            ->get();
    }

    /**
     * @param  Collection<int, RecruiterIncentiveCalculation>  $calculations
     * @return array<int, Stat>
     */
    private function statsFor(Collection $calculations, string $prefix, string $description): array
    {
        $effective = $calculations->mapWithKeys(fn (RecruiterIncentiveCalculation $c) => [$c->id => $c->effectiveAmount()]);

        $sumFor = fn (IncentiveCalculationStatus $status): float => (float) $calculations
            ->where('status', $status)
            ->sum(fn (RecruiterIncentiveCalculation $c) => $effective[$c->id]);

        $totalEarned = (float) $calculations
            ->whereNotIn('status', [IncentiveCalculationStatus::Rejected, IncentiveCalculationStatus::Reversed])
            ->sum(fn (RecruiterIncentiveCalculation $c) => $effective[$c->id]);

        return [
            Stat::make("{$prefix} Earned (This Month)", '₹'.number_format($totalEarned, 2))
                ->description($description),
            ...array_map(
                fn (IncentiveCalculationStatus $status): Stat => Stat::make("{$prefix} {$status->label()}", '₹'.number_format($sumFor($status), 2))
                    ->color($status->color()),
                self::SCORECARD_STATUSES,
            ),
        ];
    }
}
