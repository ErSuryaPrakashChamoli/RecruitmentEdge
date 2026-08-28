<?php

namespace App\Filament\Resources\RecruitmentManualActivities;

use App\Filament\Resources\RecruitmentManualActivities\Pages\CreateRecruitmentManualActivity;
use App\Filament\Resources\RecruitmentManualActivities\Pages\EditRecruitmentManualActivity;
use App\Filament\Resources\RecruitmentManualActivities\Pages\ListRecruitmentManualActivities;
use App\Filament\Resources\RecruitmentManualActivities\Schemas\RecruitmentManualActivityForm;
use App\Filament\Resources\RecruitmentManualActivities\Tables\RecruitmentManualActivitiesTable;
use App\Models\RecruitmentManualActivity;
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

class RecruitmentManualActivityResource extends Resource
{
    protected static ?string $model = RecruitmentManualActivity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Recruitment';

    protected static ?string $navigationLabel = 'Manual Activities';

    public static function form(Schema $schema): Schema
    {
        return RecruitmentManualActivityForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RecruitmentManualActivitiesTable::configure($table);
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
            'index' => ListRecruitmentManualActivities::route('/'),
            'create' => CreateRecruitmentManualActivity::route('/create'),
            'edit' => EditRecruitmentManualActivity::route('/{record}/edit'),
        ];
    }
}
