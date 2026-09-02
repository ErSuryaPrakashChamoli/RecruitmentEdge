<?php

namespace App\Filament\Widgets;

use App\Enums\AppTheme;
use App\Filament\Widgets\Concerns\ResolvesDashboardPeriod;
use App\Models\User;
use App\Services\RecruitmentAnalyticsService;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Day-by-day line-up vs turn-up trend for the selected period (Section 4), from
 * RecruitmentAnalyticsService::turnUpTrend() — no chart-specific calculation lives here.
 */
class TurnUpTrendChart extends ChartWidget
{
    use InteractsWithPageFilters, ResolvesDashboardPeriod;

    // Command Center widgets render eagerly (not lazy) so the dashboard shows real data in one
    // pass instead of a cascade of empty placeholder boxes each firing its own AJAX request.
    protected static bool $isLazy = false;

    protected ?string $heading = 'Line-up vs Turn-up Trend';

    protected int|string|array $columnSpan = 'full';

    protected bool $isCollapsible = true;

    // ChartWidget's own base view only reads $isCollapsible, never a default-collapsed state, so
    // it always renders open regardless of that flag — see the view for why this widget (and
    // SourcePerformanceWidget) needs its own copy of chart-widget.blade.php to start collapsed
    // like every other Dashboard section.
    protected string $view = 'filament.widgets.collapsed-chart-widget';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        [$start, $end] = $this->resolvePeriod();

        $rows = app(RecruitmentAnalyticsService::class)->turnUpTrend($start, $end, $this->filteredUser());

        /** @var User $user */
        $user = Filament::auth()->user();
        $theme = AppTheme::fromValueOrDefault($user->theme);

        return [
            'datasets' => [
                [
                    // Line-ups is a neutral activity count (not a status), so it's the one series
                    // that reflects the active theme's brand color — Turn-ups/No-shows stay fixed
                    // semantic success/danger colors below, unaffected by theme, since they
                    // represent a genuinely positive/negative outcome (see .ai/rules and
                    // App\Enums\AppTheme's own class doc for why semantic colors never shift).
                    'label' => 'Line-ups',
                    'data' => $rows->pluck('lineups')->all(),
                    'borderColor' => $theme->seedHex(),
                    'backgroundColor' => $theme->chartFill(),
                ],
                [
                    'label' => 'Turn-ups',
                    'data' => $rows->pluck('turnups')->all(),
                    'borderColor' => '#22c55e',
                    'backgroundColor' => 'rgba(34, 197, 94, 0.1)',
                ],
                [
                    'label' => 'No-shows',
                    'data' => $rows->pluck('no_shows')->all(),
                    'borderColor' => '#ef4444',
                    'backgroundColor' => 'rgba(239, 68, 68, 0.1)',
                ],
            ],
            'labels' => $rows->pluck('date')->all(),
        ];
    }
}
