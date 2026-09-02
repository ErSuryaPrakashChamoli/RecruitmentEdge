<?php

namespace App\Filament\Widgets;

use App\Enums\AppTheme;
use App\Filament\Widgets\Concerns\ResolvesDashboardPeriod;
use App\Models\User;
use App\Services\RecruitmentAnalyticsService;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Sourced-candidate distribution by channel (Section 14), from the existing
 * RecruitmentAnalyticsService::sourceAnalytics() — the full comparison table (interviewed/
 * selected/joined per source) already lives on the Recruitment Reports page; this widget adds the
 * at-a-glance distribution plus a best-performing-source callout to the dashboard itself.
 */
class SourcePerformanceWidget extends ChartWidget
{
    use InteractsWithPageFilters, ResolvesDashboardPeriod;

    // Command Center widgets render eagerly (not lazy) so the dashboard shows real data in one
    // pass instead of a cascade of empty placeholder boxes each firing its own AJAX request.
    protected static bool $isLazy = false;

    protected ?string $heading = 'Source Performance';

    protected bool $isCollapsible = true;

    // See TurnUpTrendChart: ChartWidget's base view only reads $isCollapsible, never a
    // default-collapsed state, so it always renders open regardless of that flag.
    protected string $view = 'filament.widgets.collapsed-chart-widget';

    protected function getType(): string
    {
        return 'doughnut';
    }

    public function getDescription(): string|Htmlable|null
    {
        [$start, $end] = $this->resolvePeriod();

        $best = app(RecruitmentAnalyticsService::class)->sourceAnalytics($start, $end)
            ->filter(fn (array $row) => $row['sourced'] > 0)
            ->map(fn (array $row) => [
                'name' => $row['source']->name,
                'rate' => round($row['joined'] / $row['sourced'] * 100, 1),
            ])
            ->sortByDesc('rate')
            ->first();

        return $best !== null
            ? "Best Sourced -> Joined rate: {$best['name']} ({$best['rate']}%)"
            : 'No sourced candidates in this period.';
    }

    protected function getData(): array
    {
        [$start, $end] = $this->resolvePeriod();

        $rows = app(RecruitmentAnalyticsService::class)->sourceAnalytics($start, $end)
            ->filter(fn (array $row) => $row['sourced'] > 0)
            ->sortByDesc('sourced');

        /** @var User $user */
        $user = Filament::auth()->user();
        $theme = AppTheme::fromValueOrDefault($user->theme);

        return [
            'datasets' => [[
                'label' => 'Sourced',
                'data' => $rows->pluck('sourced')->all(),
                // A monochromatic ramp of the active theme's own color (not an unrelated rainbow),
                // so this chart visibly belongs to whichever of the 8 themes is active.
                'backgroundColor' => $theme->chartCategoricalPalette(),
            ]],
            'labels' => $rows->map(fn (array $row) => $row['source']->name)->all(),
        ];
    }
}
