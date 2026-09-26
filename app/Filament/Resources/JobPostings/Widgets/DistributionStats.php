<?php

namespace App\Filament\Resources\JobPostings\Widgets;

use App\Services\RecruitmentAnalyticsService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Last-90-day distribution metrics (RecruitmentAnalyticsService::distributionAnalytics()).
 */
class DistributionStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $m = app(RecruitmentAnalyticsService::class)->distributionAnalytics(now()->subDays(90), now(), auth()->user());
        $top = $m['channels']->first();

        return [
            Stat::make('Live postings', $m['live_postings']),
            Stat::make('Published (90 days)', $m['published_postings']),
            Stat::make('Online applications (90 days)', $m['channels']->sum('applications')),
            Stat::make('Online hires (90 days)', $m['channels']->sum('joined')),
            Stat::make('Top channel', $top['channel'] ?? '—')->description($top !== null ? "{$top['applications']} applications" : 'No online applications yet'),
        ];
    }
}
