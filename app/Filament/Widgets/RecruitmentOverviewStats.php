<?php

namespace App\Filament\Widgets;

use App\Enums\CandidateStage;
use App\Enums\RequisitionStatus;
use App\Filament\Widgets\Concerns\ResolvesDashboardPeriod;
use App\Models\User;
use App\Services\CostPerHireService;
use App\Services\RecruitmentAnalyticsService;
use Filament\Facades\Filament;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * The Command Center's Top KPI Summary (Section 1): every funnel-stage headline count for the
 * selected period, each compared against the immediately preceding period of equal length, plus
 * point-in-time position fulfilment. Stage counts are read from
 * RecruitmentAnalyticsService::funnel() rather than re-queried here, so this widget can never
 * disagree with the funnel widget below it.
 *
 * A custom Widget (not the native StatsOverviewWidget) so each card can carry an icon and a
 * colored trend arrow via the shared kpi-stat Blade component, matching the rest of the
 * dashboard's design system rather than Filament's plain default stat card.
 */
class RecruitmentOverviewStats extends Widget
{
    use InteractsWithPageFilters, ResolvesDashboardPeriod;

    // Command Center widgets render eagerly (not lazy) so the dashboard shows real data in one
    // pass instead of a cascade of empty placeholder boxes each firing its own AJAX request.
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.recruitment-overview-stats';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<int, array{label: string, value: string, icon: string, color: string, trend: float|null, trendLabel: string|null, sparkline?: array<int, int>|null, progress?: array{current: int, target: int}|null}>
     */
    public function getCards(): array
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $analytics = app(RecruitmentAnalyticsService::class);

        [$start, $end] = $this->resolvePeriod();
        $days = $start->diffInDays($end) + 1;
        $previousEnd = $start->copy()->subDay()->endOfDay();
        $previousStart = $previousEnd->copy()->subDays($days - 1)->startOfDay();

        $funnel = $analytics->funnel($start, $end, $user)->keyBy(fn (array $row) => $row['stage']->value);
        $previousFunnel = $analytics->funnel($previousStart, $previousEnd, $user)->keyBy(fn (array $row) => $row['stage']->value);

        $turnUp = $analytics->turnUpAnalysis($start, $end, $user);
        $positionHealth = $analytics->positionHealth($user);
        $avgTimeToHire = $analytics->averageTimeToHireDays($start, $end, $user);
        $costPerHire = app(CostPerHireService::class)->costPerHire($start, $end);

        $openPositions = $positionHealth->filter(fn (array $row) => $row['requisition']->status === RequisitionStatus::Open)->count();
        $remaining = $positionHealth->sum('remaining');
        $filled = $positionHealth->sum('filled');
        $required = $positionHealth->sum('required');
        $turnUpSeries = $analytics->turnUpTrend($start, $end, $user)->pluck('turnups')->all();

        return [
            ['label' => 'Open Positions', 'value' => (string) $openPositions, 'icon' => 'heroicon-o-briefcase', 'color' => 'info', 'trend' => null, 'trendLabel' => null],
            [
                'label' => 'Positions Filled', 'value' => (string) $filled, 'icon' => 'heroicon-o-check-badge', 'color' => 'success', 'trend' => null, 'trendLabel' => null,
                'progress' => $required > 0 ? ['current' => $filled, 'target' => $required] : null,
            ],
            ['label' => 'Positions Remaining', 'value' => (string) $remaining, 'icon' => 'heroicon-o-clock', 'color' => $remaining > 0 ? 'warning' : 'success', 'trend' => null, 'trendLabel' => null],
            $this->stageCard('Applications', 'heroicon-o-inbox-arrow-down', 'info', CandidateStage::Sourced, $funnel, $previousFunnel),
            $this->stageCard('Shortlisted', 'heroicon-o-star', 'info', CandidateStage::Shortlisted, $funnel, $previousFunnel),
            $this->stageCard('Line-ups', 'heroicon-o-calendar-days', 'info', CandidateStage::InterviewScheduled, $funnel, $previousFunnel),
            [
                'label' => 'Turn-ups', 'value' => (string) $turnUp['turnups'], 'icon' => 'heroicon-o-arrow-trending-up', 'color' => 'info', 'trend' => null,
                'trendLabel' => $turnUp['turnup_percent'] !== null ? "{$turnUp['turnup_percent']}% turn-up ratio" : 'No line-ups in period',
                'sparkline' => count($turnUpSeries) >= 2 ? $turnUpSeries : null,
            ],
            $this->stageCard('Selections', 'heroicon-o-check-circle', 'success', CandidateStage::Selected, $funnel, $previousFunnel),
            $this->stageCard('Offers', 'heroicon-o-document-text', 'info', CandidateStage::OfferReleased, $funnel, $previousFunnel),
            $this->stageCard('Offer Accepted', 'heroicon-o-hand-thumb-up', 'success', CandidateStage::OfferAccepted, $funnel, $previousFunnel),
            $this->stageCard('Joining', 'heroicon-o-flag', 'success', CandidateStage::Joined, $funnel, $previousFunnel),
            ['label' => 'Avg. Time to Hire', 'value' => $avgTimeToHire !== null ? "{$avgTimeToHire} days" : '—', 'icon' => 'heroicon-o-clock', 'color' => 'default', 'trend' => null, 'trendLabel' => null],
            ['label' => 'Cost per Hire', 'value' => $costPerHire !== null ? '₹'.number_format($costPerHire, 2) : '—', 'icon' => 'heroicon-o-banknotes', 'color' => 'default', 'trend' => null, 'trendLabel' => null],
        ];
    }

    /**
     * @param  Collection<string, array{stage: CandidateStage, count: int, conversion_from_sourced: float|null}>  $funnel
     * @param  Collection<string, array{stage: CandidateStage, count: int, conversion_from_sourced: float|null}>  $previousFunnel
     * @return array{label: string, value: string, icon: string, color: string, trend: float|null, trendLabel: string|null}
     */
    private function stageCard(string $label, string $icon, string $color, CandidateStage $stage, Collection $funnel, Collection $previousFunnel): array
    {
        $count = $funnel->get($stage->value)['count'] ?? 0;
        $previousCount = $previousFunnel->get($stage->value)['count'] ?? 0;

        if ($previousCount === 0) {
            return [
                'label' => $label,
                'value' => (string) $count,
                'icon' => $icon,
                'color' => $color,
                'trend' => null,
                'trendLabel' => $count > 0 ? 'vs 0 in previous period' : 'No change vs previous period',
            ];
        }

        return [
            'label' => $label,
            'value' => (string) $count,
            'icon' => $icon,
            'color' => $color,
            'trend' => round(($count - $previousCount) / $previousCount * 100, 1),
            'trendLabel' => 'vs previous period',
        ];
    }
}
