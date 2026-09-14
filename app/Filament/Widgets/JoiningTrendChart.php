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
 * Expected joiners vs actually joined per period bucket for the selected period, from
 * RecruitmentAnalyticsService::joiningTrend() — the joining counterpart to TurnUpTrendChart's
 * interview line-ups. Not registered on the main Dashboard by itself (see Dashboard::getWidgets()).
 */
class JoiningTrendChart extends ChartWidget
{
    use InteractsWithPageFilters, ResolvesDashboardPeriod;

    protected static bool $isLazy = false;

    protected ?string $heading = 'Expected vs Actual Joinings';

    protected int|string|array $columnSpan = 'full';

    protected bool $isCollapsible = true;

    protected string $view = 'filament.widgets.collapsed-chart-widget';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        [$start, $end] = $this->resolvePeriod();

        $rows = app(RecruitmentAnalyticsService::class)->joiningTrend($start, $end, $this->filteredUser());

        /** @var User $user */
        $user = Filament::auth()->user();
        $theme = AppTheme::fromValueOrDefault($user->theme);

        return [
            'datasets' => [
                [
                    // Expected is a neutral plan figure, so it follows the theme's brand color;
                    // Joined is a positive outcome and keeps the fixed semantic success color.
                    'label' => 'Expected Joiners',
                    'data' => $rows->pluck('expected')->all(),
                    'borderColor' => $theme->seedHex(),
                    'backgroundColor' => $theme->chartFill(),
                ],
                [
                    'label' => 'Joined',
                    'data' => $rows->pluck('joined')->all(),
                    'borderColor' => '#22c55e',
                    'backgroundColor' => 'rgba(34, 197, 94, 0.5)',
                ],
            ],
            'labels' => $rows->pluck('period')->all(),
        ];
    }
}
