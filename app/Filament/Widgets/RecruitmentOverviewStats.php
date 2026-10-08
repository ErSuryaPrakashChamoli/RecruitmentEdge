<?php

namespace App\Filament\Widgets;

use App\Enums\CandidateStage;
use App\Enums\RequisitionStatus;
use App\Filament\Widgets\Concerns\AuthorizesWidget;
use App\Filament\Widgets\Concerns\ResolvesDashboardPeriod;
use App\Services\CostPerHireService;
use App\Services\Metrics\MetricResult;
use App\Services\Metrics\MetricService;
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
    use AuthorizesWidget, InteractsWithPageFilters, ResolvesDashboardPeriod;

    // Command Center widgets render eagerly (not lazy) so the dashboard shows real data in one
    // pass instead of a cascade of empty placeholder boxes each firing its own AJAX request.
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.recruitment-overview-stats';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<int, array{label: string, value: string, icon: string, color: string, trend: float|null, trendLabel: string|null, sparkline?: array<int, int>|null, progress?: array{current: int, target: int}|null, definition?: string|null}>
     */
    public function getCards(): array
    {
        $user = $this->filteredUser();
        $analytics = app(RecruitmentAnalyticsService::class);

        $period = $this->resolveMetricPeriod();
        [$start, $end] = $this->resolvePeriod();
        // Like-for-like trends: a period still in progress (This Month, This Week) is compared over
        // the days elapsed so far, not a partial current period against a full previous one.
        $elapsed = $period->elapsed();
        $previous = $elapsed->previous();

        // Phase 8.5: stage cards are pipeline volumes (pipeline.stage_activity — genuine stage
        // entries only); Joining is hires (hiring.hires), never the pipeline stage.
        $activity = $analytics->stageActivity($elapsed->from, $elapsed->to->endOfDay(), $user)->keyBy(fn (array $row) => $row['stage']->value);
        $previousActivity = $analytics->stageActivity($previous->from, $previous->to->endOfDay(), $user)->keyBy(fn (array $row) => $row['stage']->value);
        $current = $analytics->stageActivity($start, $end, $user)->keyBy(fn (array $row) => $row['stage']->value);

        $turnUp = $analytics->turnUpAnalysis($start, $end, $user);
        $positionHealth = $analytics->positionHealth($user);
        $timeToHire = $analytics->metric('hiring.time_to_hire', $start, $end, $user);
        $costPerHire = app(CostPerHireService::class)->result($start, $end, user: $user);
        $hires = $analytics->metric('hiring.hires', $start, $end, $user);

        $openPositions = $positionHealth->filter(fn (array $row) => $row['requisition']->status === RequisitionStatus::Open)->count();
        $remaining = $positionHealth->sum('remaining');
        $turnUpSeries = $analytics->turnUpTrend($start, $end, $user)->pluck('turnups')->all();

        return [
            ['label' => 'Open Positions', 'value' => (string) $openPositions, 'icon' => 'heroicon-o-briefcase', 'color' => 'info', 'trend' => null, 'trendLabel' => null],
            [
                'label' => 'Positions Filled', 'value' => $hires->display(), 'icon' => 'heroicon-o-check-badge', 'color' => 'success', 'trend' => null,
                'trendLabel' => 'hires in period', 'definition' => $this->definition($hires),
            ],
            ['label' => 'Positions Remaining', 'value' => (string) $remaining, 'icon' => 'heroicon-o-clock', 'color' => $remaining > 0 ? 'warning' : 'success', 'trend' => null, 'trendLabel' => 'open requisitions, today'],
            $this->stageCard('Applications', 'heroicon-o-inbox-arrow-down', 'info', CandidateStage::Sourced, $current, $activity, $previousActivity),
            $this->stageCard('Shortlisted', 'heroicon-o-star', 'info', CandidateStage::Shortlisted, $current, $activity, $previousActivity),
            $this->stageCard('Line-ups', 'heroicon-o-calendar-days', 'info', CandidateStage::InterviewScheduled, $current, $activity, $previousActivity),
            [
                'label' => 'Turn-ups', 'value' => (string) $turnUp['turnups'], 'icon' => 'heroicon-o-arrow-trending-up', 'color' => 'info', 'trend' => null,
                'trendLabel' => $turnUp['turnup_percent'] !== null ? "{$turnUp['turnup_percent']}% turn-up ratio" : 'No recorded line-ups in period',
                'sparkline' => count($turnUpSeries) >= 2 ? $turnUpSeries : null,
            ],
            $this->stageCard('Selections', 'heroicon-o-check-circle', 'success', CandidateStage::Selected, $current, $activity, $previousActivity),
            $this->stageCard('Offers', 'heroicon-o-document-text', 'info', CandidateStage::OfferReleased, $current, $activity, $previousActivity),
            $this->stageCard('Offer Accepted', 'heroicon-o-hand-thumb-up', 'success', CandidateStage::OfferAccepted, $current, $activity, $previousActivity),
            $this->stageCard('Joining', 'heroicon-o-flag', 'success', CandidateStage::Joined, $current, $activity, $previousActivity),
            ['label' => 'Median Time to Hire', 'value' => $timeToHire->display(), 'icon' => 'heroicon-o-clock', 'color' => 'default', 'trend' => null, 'trendLabel' => "n = {$timeToHire->sampleSize}", 'definition' => $this->definition($timeToHire)],
            ['label' => 'Cost per Hire', 'value' => $costPerHire->display(), 'icon' => 'heroicon-o-banknotes', 'color' => 'default', 'trend' => null, 'trendLabel' => null, 'definition' => $this->definition($costPerHire)],
        ];
    }

    private function definition(MetricResult $result): string
    {
        return app(MetricService::class)->spec($result->key)->description.' — '.$result->basisLine();
    }

    /**
     * @param  Collection<string, array{stage: CandidateStage, count: int}>  $current  the whole selected period
     * @param  Collection<string, array{stage: CandidateStage, count: int}>  $elapsed  the elapsed part (for the trend)
     * @param  Collection<string, array{stage: CandidateStage, count: int}>  $previous  the equal-length period before it
     * @return array{label: string, value: string, icon: string, color: string, trend: float|null, trendLabel: string|null}
     */
    private function stageCard(string $label, string $icon, string $color, CandidateStage $stage, Collection $current, Collection $elapsed, Collection $previous): array
    {
        $count = $current->get($stage->value)['count'] ?? 0;
        $elapsedCount = $elapsed->get($stage->value)['count'] ?? 0;
        $previousCount = $previous->get($stage->value)['count'] ?? 0;

        if ($previousCount === 0) {
            return [
                'label' => $label,
                'value' => (string) $count,
                'icon' => $icon,
                'color' => $color,
                'trend' => null,
                'trendLabel' => $elapsedCount > 0 ? 'vs 0 in previous period' : 'No change vs previous period',
            ];
        }

        return [
            'label' => $label,
            'value' => (string) $count,
            'icon' => $icon,
            'color' => $color,
            'trend' => round(($elapsedCount - $previousCount) / $previousCount * 100, 1),
            'trendLabel' => 'vs previous period',
        ];
    }
}
