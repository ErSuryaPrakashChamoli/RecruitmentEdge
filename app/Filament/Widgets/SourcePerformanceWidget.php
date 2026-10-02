<?php

namespace App\Filament\Widgets;

use App\Enums\AppTheme;
use App\Filament\Widgets\Concerns\AuthorizesWidget;
use App\Filament\Widgets\Concerns\LoadsAfterFirstPaint;
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
    use AuthorizesWidget, InteractsWithPageFilters, LoadsAfterFirstPaint, ResolvesDashboardPeriod;

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

        $best = app(RecruitmentAnalyticsService::class)->sourceAnalytics($start, $end, $this->filteredUser())
            ->filter(fn (array $row) => $row['sourced'] > 0)
            // Phase 8.5: the same conversion the Source ROI report shows, with no rate below the
            // minimum sample (one lucky application is not "the best source").
            ->filter(fn (array $row) => $row['sourced'] >= (int) config('metrics.min_sample', 3))
            ->map(fn (array $row) => ['name' => $row['source_name'], 'rate' => $row['conversion_percent']])
            ->sortByDesc('rate')
            ->first();

        return $best !== null
            ? "Best Applications -> Joined rate: {$best['name']} ({$best['rate']}%)"
            : 'Not enough applications in this period to compare sources.';
    }

    protected function getData(): array
    {
        [$start, $end] = $this->resolvePeriod();

        $rows = app(RecruitmentAnalyticsService::class)->sourceAnalytics($start, $end, $this->filteredUser())
            ->filter(fn (array $row) => $row['sourced'] > 0)
            ->sortByDesc('sourced')
            // Re-index: filtered/sorted keys would make the labels encode as a JSON object, which
            // Chart.js can't read (it needs an array of labels).
            ->values();

        /** @var User $user */
        $user = Filament::auth()->user();
        $theme = AppTheme::fromValueOrDefault($user->theme);

        return [
            'datasets' => [[
                'label' => 'Applications',
                'data' => $rows->pluck('sourced')->all(),
                // A monochromatic ramp of the active theme's own color (not an unrelated rainbow),
                // so this chart visibly belongs to whichever of the 8 themes is active.
                'backgroundColor' => $theme->chartCategoricalPalette(),
            ]],
            'labels' => $rows->map(fn (array $row) => $row['source_name'])->all(),
        ];
    }
}
