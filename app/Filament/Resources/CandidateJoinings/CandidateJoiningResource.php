<?php

namespace App\Filament\Resources\CandidateJoinings;

use App\Filament\Resources\CandidateJoinings\Pages\CreateCandidateJoining;
use App\Filament\Resources\CandidateJoinings\Pages\EditCandidateJoining;
use App\Filament\Resources\CandidateJoinings\Pages\ListCandidateJoinings;
use App\Filament\Resources\CandidateJoinings\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\CandidateJoinings\Schemas\CandidateJoiningForm;
use App\Filament\Resources\CandidateJoinings\Tables\CandidateJoiningsTable;
use App\Models\CandidateJoining;
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

class CandidateJoiningResource extends Resource
{
    protected static ?string $model = CandidateJoining::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static string|UnitEnum|null $navigationGroup = 'Recruitment';

    protected static ?string $navigationLabel = 'Joining Tracker';

    public static function form(Schema $schema): Schema
    {
        return CandidateJoiningForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CandidateJoiningsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        /** @var User $user */
        $user = Filament::auth()->user();

        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);

        if ($visibleIds === null) {
            return $query;
        }

        return $query->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds));
    }

    public static function getRelations(): array
    {
        return [
            DocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCandidateJoinings::route('/'),
            'create' => CreateCandidateJoining::route('/create'),
            'edit' => EditCandidateJoining::route('/{record}/edit'),
        ];
    }
}
