<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ResolvesDashboardPeriod;
use App\Services\RecruitmentAnalyticsService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * Interview completion/no-show/selection rates and interviewer breakdown (Sections 16/17), from
 * RecruitmentAnalyticsService::interviewAnalytics().
 */
class InterviewAnalyticsWidget extends Widget
{
    use InteractsWithPageFilters, ResolvesDashboardPeriod;

    // Command Center widgets render eagerly (not lazy) so the dashboard shows real data in one
    // pass instead of a cascade of empty placeholder boxes each firing its own AJAX request.
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.interview-analytics';

    protected int|string|array $columnSpan = 1;

    public function getAnalytics(): array
    {
        [$start, $end] = $this->resolvePeriod();

        return app(RecruitmentAnalyticsService::class)->interviewAnalytics($start, $end, $this->filteredUser());
    }
}
