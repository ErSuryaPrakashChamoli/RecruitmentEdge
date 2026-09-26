<?php

namespace App\Filament\Resources\CandidateCommunicationPreferences;

use App\Filament\Resources\CandidateCommunicationPreferences\Pages\ListCandidateCommunicationPreferences;
use App\Filament\Resources\CandidateCommunicationPreferences\Tables\CandidateCommunicationPreferencesTable;
use App\Filament\Resources\Candidates\Schemas\CandidatePicker;
use App\Models\CandidateCommunicationPreference;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Recorded candidate channel preferences, scoped to candidates the viewer can see. Channels with
 * no row are "Unknown". Changes are made per candidate (EditPreferencesAction).
 */
class CandidateCommunicationPreferenceResource extends Resource
{
    protected static ?string $model = CandidateCommunicationPreference::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Communication';

    protected static ?string $navigationLabel = 'Communication Preferences';

    protected static ?string $modelLabel = 'communication preference';

    public static function table(Table $table): Table
    {
        return CandidateCommunicationPreferencesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('candidate_id', CandidatePicker::selectableCandidates()->select('candidates.id'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCandidateCommunicationPreferences::route('/'),
        ];
    }
}
