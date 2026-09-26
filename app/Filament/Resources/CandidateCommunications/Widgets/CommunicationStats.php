<?php

namespace App\Filament\Resources\CandidateCommunications\Widgets;

use App\Models\User;
use App\Services\RecruitmentAnalyticsService;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Last-30-day communication metrics for the Communication Center, from
 * RecruitmentAnalyticsService::communicationAnalytics(). Metrics no provider reported read
 * "Not reported" — never an invented 0% or 100%.
 */
class CommunicationStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $m = app(RecruitmentAnalyticsService::class)->communicationAnalytics(now()->subDays(30), now(), $user);

        return [
            Stat::make('Messages (30 days)', $m['total'])->description("{$m['sent']} handed to providers"),
            Stat::make('Delivered', $m['delivered'] ?? 'Not reported')->description($m['delivery_rate'] !== null ? "{$m['delivery_rate']}% delivery rate" : 'Provider delivery reports only'),
            Stat::make('Failed', $m['failed'])->color($m['failed'] > 0 ? 'danger' : 'gray'),
            Stat::make('Blocked', $m['blocked'])->description('Opt-outs, no consent or no provider'),
            Stat::make('Opted out', $m['opted_out']),
            Stat::make('Interview confirmations', $m['interview_confirmations']),
        ];
    }
}
