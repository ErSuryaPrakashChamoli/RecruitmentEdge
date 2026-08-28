<?php

namespace App\Filament\Resources\CandidateSources;

use App\Filament\Resources\CandidateSources\Pages\CreateCandidateSource;
use App\Filament\Resources\CandidateSources\Pages\EditCandidateSource;
use App\Filament\Resources\CandidateSources\Pages\ListCandidateSources;
use App\Filament\Resources\CandidateSources\Schemas\CandidateSourceForm;
use App\Filament\Resources\CandidateSources\Tables\CandidateSourcesTable;
use App\Models\CandidateSource;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class CandidateSourceResource extends Resource
{
    protected static ?string $model = CandidateSource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return CandidateSourceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CandidateSourcesTable::configure($table);
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
            'index' => ListCandidateSources::route('/'),
            'create' => CreateCandidateSource::route('/create'),
            'edit' => EditCandidateSource::route('/{record}/edit'),
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
