<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\AuthorizesWidget;
use App\Filament\Widgets\Concerns\LoadsAfterFirstPaint;
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
    use AuthorizesWidget, InteractsWithPageFilters, LoadsAfterFirstPaint, ResolvesDashboardPeriod;

    protected string $view = 'filament.widgets.interview-analytics';

    protected int|string|array $columnSpan = 1;

    public function getAnalytics(): array
    {
        [$start, $end] = $this->resolvePeriod();

        return app(RecruitmentAnalyticsService::class)->interviewAnalytics($start, $end, $this->filteredUser());
    }
}
