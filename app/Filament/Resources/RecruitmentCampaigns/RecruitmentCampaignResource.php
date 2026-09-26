<?php

namespace App\Filament\Resources\RecruitmentCampaigns;

use App\Filament\Resources\RecruitmentCampaigns\Pages\CreateRecruitmentCampaign;
use App\Filament\Resources\RecruitmentCampaigns\Pages\EditRecruitmentCampaign;
use App\Filament\Resources\RecruitmentCampaigns\Pages\ListRecruitmentCampaigns;
use App\Filament\Resources\RecruitmentCampaigns\Pages\ViewRecruitmentCampaign;
use App\Filament\Resources\RecruitmentCampaigns\Schemas\RecruitmentCampaignForm;
use App\Filament\Resources\RecruitmentCampaigns\Schemas\RecruitmentCampaignInfolist;
use App\Filament\Resources\RecruitmentCampaigns\Tables\RecruitmentCampaignsTable;
use App\Models\RecruitmentCampaign;
use App\Models\User;
use App\Services\HierarchyService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Phase 5 Recruitment Campaigns: budget/target groupings of existing requisitions and sources,
 * with metrics from RecruitmentAnalyticsService::campaignAnalytics().
 */
class RecruitmentCampaignResource extends Resource
{
    protected static ?string $model = RecruitmentCampaign::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static string|UnitEnum|null $navigationGroup = 'Distribution';

    protected static ?string $navigationLabel = 'Recruitment Campaigns';

    protected static ?string $modelLabel = 'campaign';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return RecruitmentCampaignForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return RecruitmentCampaignInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RecruitmentCampaignsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);

        return parent::getEloquentQuery()->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('owner_id', $visibleIds));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecruitmentCampaigns::route('/'),
            'create' => CreateRecruitmentCampaign::route('/create'),
            'view' => ViewRecruitmentCampaign::route('/{record}'),
            'edit' => EditRecruitmentCampaign::route('/{record}/edit'),
        ];
    }
}
