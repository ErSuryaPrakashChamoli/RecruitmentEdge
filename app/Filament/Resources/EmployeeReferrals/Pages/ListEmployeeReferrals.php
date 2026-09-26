<?php

namespace App\Filament\Resources\EmployeeReferrals\Pages;

use App\Filament\Resources\EmployeeReferrals\EmployeeReferralResource;
use App\Filament\Resources\EmployeeReferrals\Widgets\ReferralLeaderboard;
use App\Filament\Resources\EmployeeReferrals\Widgets\ReferralStats;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * The referral dashboard: KPIs and the referrer leaderboard sit above the (hierarchy-scoped)
 * referral list rather than on the main dashboard.
 */
class ListEmployeeReferrals extends ListRecords
{
    protected static string $resource = EmployeeReferralResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Submit a referral'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ReferralStats::class,
            ReferralLeaderboard::class,
        ];
    }
}
