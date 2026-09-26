<?php

namespace App\Filament\Resources\CandidateCommunications;

use App\Filament\Resources\CandidateCommunications\Pages\ListCandidateCommunications;
use App\Filament\Resources\CandidateCommunications\Pages\ViewCandidateCommunication;
use App\Filament\Resources\CandidateCommunications\Schemas\CandidateCommunicationInfolist;
use App\Filament\Resources\CandidateCommunications\Tables\CandidateCommunicationsTable;
use App\Filament\Resources\Candidates\Schemas\CandidatePicker;
use App\Models\CandidateCommunication;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Phase 5 Communication Center: every candidate message and its delivery state, scoped to
 * candidates the viewer can see (same hierarchy scope as the Candidates list). Messages are sent
 * through SendMessageAction → CommunicationService and are never edited here.
 */
class CandidateCommunicationResource extends Resource
{
    protected static ?string $model = CandidateCommunication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Communication';

    protected static ?string $navigationLabel = 'Communication Center';

    protected static ?string $modelLabel = 'message';

    public static function infolist(Schema $schema): Schema
    {
        return CandidateCommunicationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CandidateCommunicationsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('candidate_id', CandidatePicker::selectableCandidates()->select('candidates.id'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCandidateCommunications::route('/'),
            'view' => ViewCandidateCommunication::route('/{record}'),
        ];
    }
}
