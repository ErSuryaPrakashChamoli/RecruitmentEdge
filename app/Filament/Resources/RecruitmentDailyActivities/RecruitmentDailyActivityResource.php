<?php

namespace App\Filament\Resources\RecruitmentDailyActivities;

use App\Filament\Resources\RecruitmentDailyActivities\Pages\CreateRecruitmentDailyActivity;
use App\Filament\Resources\RecruitmentDailyActivities\Pages\EditRecruitmentDailyActivity;
use App\Filament\Resources\RecruitmentDailyActivities\Pages\ListRecruitmentDailyActivities;
use App\Filament\Resources\RecruitmentDailyActivities\Schemas\RecruitmentDailyActivityForm;
use App\Filament\Resources\RecruitmentDailyActivities\Tables\RecruitmentDailyActivitiesTable;
use App\Models\RecruitmentDailyActivity;
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

class RecruitmentDailyActivityResource extends Resource
{
    protected static ?string $model = RecruitmentDailyActivity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;

    protected static string|UnitEnum|null $navigationGroup = 'Recruitment';

    public static function form(Schema $schema): Schema
    {
        return RecruitmentDailyActivityForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RecruitmentDailyActivitiesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        /** @var User $user */
        $user = Filament::auth()->user();

        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);

        return $visibleIds === null ? $query : $query->whereIn('recruiter_id', $visibleIds);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecruitmentDailyActivities::route('/'),
            'create' => CreateRecruitmentDailyActivity::route('/create'),
            'edit' => EditRecruitmentDailyActivity::route('/{record}/edit'),
        ];
    }
}
