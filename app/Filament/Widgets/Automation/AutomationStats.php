<?php

namespace App\Filament\Widgets\Automation;

use App\Models\User;
use App\Services\RecruitmentAnalyticsService;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Last-30-day automation activity (RecruitmentAnalyticsService::automationAnalytics), shown only on
 * the Automation Dashboard — not on the main dashboard. Reports what happened; it does not claim
 * automation caused any hiring outcome.
 */
class AutomationStats extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected function getStats(): array
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $m = app(RecruitmentAnalyticsService::class)->automationAnalytics(now()->subDays(30), now(), $user);
        $finished = $m['completed'] + $m['partially_completed'] + $m['failed'];

        return [
            Stat::make('Active rules', $m['active_rules']),
            Stat::make('Runs (30 days)', $m['executions'])->description("{$m['completed']} completed · {$m['skipped']} skipped"),
            Stat::make('Failed', $m['failed'] + $m['partially_completed'])
                ->description($finished > 0 ? round(($m['failed'] + $m['partially_completed']) / $finished * 100, 1).'% of runs that acted' : 'No runs acted yet')
                ->color($m['failed'] > 0 ? 'danger' : 'gray'),
            Stat::make('Retried', $m['retried']),
            Stat::make('Actions created / done', "{$m['actions_created']} / {$m['actions_completed']}")
                ->description($m['avg_resolution_hours'] !== null ? "Avg. {$m['avg_resolution_hours']} h to complete" : 'No completed actions yet'),
            Stat::make('Escalations sent', $m['escalations_sent'])->description("{$m['escalations_resolved_first']} resolved before escalating"),
            Stat::make('Messages sent by rules', $m['communication_actions']),
        ];
    }
}
