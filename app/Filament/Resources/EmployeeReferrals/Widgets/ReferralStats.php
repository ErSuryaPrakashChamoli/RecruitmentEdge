<?php

namespace App\Filament\Resources\EmployeeReferrals\Widgets;

use App\Enums\ReferralStatus;
use App\Models\EmployeeReferral;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Referral KPIs over the referrals the viewer can see (one grouped query). Conversion = joined ÷
 * referrals that reached a final outcome, so open referrals don't dilute it.
 */
class ReferralStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        $counts = EmployeeReferral::query()
            ->visibleTo($user)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $count = fn (ReferralStatus ...$statuses): int => (int) collect($statuses)->sum(fn (ReferralStatus $s) => $counts->get($s->value, 0));

        $mine = $user->employee_id !== null
            ? EmployeeReferral::query()->where('referrer_id', $user->employee_id)->count()
            : 0;

        $joined = $count(ReferralStatus::Joined);
        $decided = $joined + $count(ReferralStatus::Rejected, ReferralStatus::DidNotJoin);

        return [
            Stat::make('My referrals', $mine),
            Stat::make('Pending review', $count(ReferralStatus::Submitted, ReferralStatus::UnderReview)),
            Stat::make('In process', $count(ReferralStatus::Accepted, ReferralStatus::InProcess)),
            Stat::make('Selected / offered', $count(ReferralStatus::Selected, ReferralStatus::OfferReleased)),
            Stat::make('Joined', $joined),
            Stat::make('Rejected / did not join', $count(ReferralStatus::Rejected, ReferralStatus::DidNotJoin)),
            Stat::make('Referral conversion', $decided > 0 ? round($joined / $decided * 100, 1).'%' : '—')
                ->description('Joined out of decided referrals'),
        ];
    }
}
