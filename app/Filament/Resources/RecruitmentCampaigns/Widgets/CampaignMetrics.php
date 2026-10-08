<?php

namespace App\Filament\Resources\RecruitmentCampaigns\Widgets;

use App\Models\RecruitmentCampaign;
use App\Services\RecruitmentAnalyticsService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;

/**
 * Campaign funnel and spend (RecruitmentAnalyticsService::campaignAnalytics()) on the campaign page.
 */
class CampaignMetrics extends StatsOverviewWidget
{
    public ?Model $record = null;

    protected function getStats(): array
    {
        if (! $this->record instanceof RecruitmentCampaign) {
            return [];
        }

        $m = app(RecruitmentAnalyticsService::class)->campaignAnalytics($this->record, auth()->user());

        return [
            Stat::make('Applications', $m['applications'])->description("{$m['screened']} screened · {$m['interviewed']} interviewed"),
            Stat::make('Offers', $m['offers']),
            Stat::make('Joined', $m['joined'])->description($m['target_progress_percent'] !== null ? "{$m['target_progress_percent']}% of target {$m['target_hires']}" : 'No target set'),
            Stat::make('Spend', '₹'.number_format($m['spend']))->description($m['budget_used_percent'] !== null ? "{$m['budget_used_percent']}% of budget" : 'No budget set'),
            Stat::make('Cost per hire', $m['cost_per_hire'] !== null ? '₹'.number_format($m['cost_per_hire']) : '—'),
            Stat::make('Conversion', $m['conversion_percent'] !== null ? "{$m['conversion_percent']}%" : '—')->description('Applications to joins'),
        ];
    }
}
