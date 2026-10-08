<?php

namespace App\Filament\Resources\TalentPools\Widgets;

use App\Models\TalentPool;
use App\Models\TalentPoolMembership;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Contextual Talent Pool KPIs for the Talent Pools list page (kept off the main dashboard on
 * purpose), limited to the pools the viewer can see. Three aggregate queries regardless of size.
 */
class TalentPoolStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        $poolIds = TalentPool::query()->visibleTo($user)->active()->select('id');

        $members = TalentPoolMembership::query()->whereIn('talent_pool_id', $poolIds)->whereNull('removed_at');

        return [
            Stat::make('Active talent pools', TalentPool::query()->visibleTo($user)->active()->count()),
            Stat::make('Candidates in pools', (clone $members)->distinct()->count('candidate_id'))
                ->description('Distinct candidates across your pools'),
            Stat::make('Added in the last 30 days', (clone $members)->where('added_at', '>=', now()->subDays(30))->count()),
        ];
    }
}
