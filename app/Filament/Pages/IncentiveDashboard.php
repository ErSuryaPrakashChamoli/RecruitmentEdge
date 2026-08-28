<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\IncentiveDashboardStats;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class IncentiveDashboard extends Page
{
    protected string $view = 'filament.pages.incentive-dashboard';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    protected static string|UnitEnum|null $navigationGroup = 'Incentives';

    protected static ?string $navigationLabel = 'Incentive Dashboard';

    protected static ?int $navigationSort = -1;

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can('incentives.view');
    }

    protected function getHeaderWidgets(): array
    {
        return [
            IncentiveDashboardStats::class,
        ];
    }
}
