<?php

namespace App\Filament\Resources\CandidateApplications;

use App\Filament\Resources\CandidateApplications\Pages\CreateCandidateApplication;
use App\Filament\Resources\CandidateApplications\Pages\EditCandidateApplication;
use App\Filament\Resources\CandidateApplications\Pages\ListCandidateApplications;
use App\Filament\Resources\CandidateApplications\Pages\ViewCandidateApplication;
use App\Filament\Resources\CandidateApplications\RelationManagers\ActivitiesRelationManager;
use App\Filament\Resources\CandidateApplications\RelationManagers\InterviewsRelationManager;
use App\Filament\Resources\CandidateApplications\RelationManagers\OffersRelationManager;
use App\Filament\Resources\CandidateApplications\RelationManagers\StageHistoryRelationManager;
use App\Filament\Resources\CandidateApplications\Schemas\CandidateApplicationForm;
use App\Filament\Resources\CandidateApplications\Schemas\CandidateApplicationInfolist;
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
use Illuminate\Database\Eloquent\Model;
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

    public static function infolist(Schema $schema): Schema
    {
        return CandidateApplicationInfolist::configure($schema);
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
            InterviewsRelationManager::class,
            OffersRelationManager::class,
            ActivitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCandidateApplications::route('/'),
            'create' => CreateCandidateApplication::route('/create'),
            'view' => ViewCandidateApplication::route('/{record}'),
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

    /**
     * Powers the panel's global search (Cmd/Ctrl+K) — inherits hierarchy scoping for free since it
     * builds on getEloquentQuery() by default (see getGlobalSearchEloquentQuery() below).
     *
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['application_code'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return "{$record->candidate->full_name} — {$record->application_code}";
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Stage' => $record->current_stage->label(),
            'Requisition' => $record->requisition?->code ?? '—',
        ];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['candidate', 'requisition']);
    }
}
