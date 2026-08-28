<?php

namespace App\Filament\Resources\CandidateApplications;

use App\Filament\Resources\CandidateApplications\Pages\CreateCandidateApplication;
use App\Filament\Resources\CandidateApplications\Pages\EditCandidateApplication;
use App\Filament\Resources\CandidateApplications\Pages\ListCandidateApplications;
use App\Filament\Resources\CandidateApplications\RelationManagers\StageHistoryRelationManager;
use App\Filament\Resources\CandidateApplications\Schemas\CandidateApplicationForm;
use App\Filament\Resources\CandidateApplications\Tables\CandidateApplicationsTable;
use App\Models\CandidateApplication;
use App\Models\User;
use App\Services\HierarchyService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class CandidateApplicationResource extends Resource
{
    protected static ?string $model = CandidateApplication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Recruitment';

    protected static ?string $navigationLabel = 'Applications';

    protected static ?string $recordTitleAttribute = 'application_code';

    public static function form(Schema $schema): Schema
    {
        return CandidateApplicationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CandidateApplicationsTable::configure($table);
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
            StageHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCandidateApplications::route('/'),
            'create' => CreateCandidateApplication::route('/create'),
            'edit' => EditCandidateApplication::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
