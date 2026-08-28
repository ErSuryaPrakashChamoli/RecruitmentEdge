<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\RecruitmentOverviewStats;
use App\Filament\Widgets\TodaysRecruitmentPulse;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;

/**
 * Overrides the built-in Dashboard's getWidgets(), which otherwise returns Filament::getWidgets()
 * — every widget under app/Filament/Widgets, discovered or not (including page-specific ones like
 * IncentiveDashboardStats, which belongs only on the Incentive Dashboard page). This is the
 * explicit, curated set for the main dashboard (Section 5/6).
 */
class Dashboard extends BaseDashboard
{
    public function getWidgets(): array
    {
        return [
            AccountWidget::class,
            RecruitmentOverviewStats::class,
            TodaysRecruitmentPulse::class,
            FilamentInfoWidget::class,
        ];
    }
}
