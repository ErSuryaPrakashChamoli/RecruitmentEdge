<?php

namespace App\Filament\Resources\CandidatePortalAccounts;

use App\Filament\Resources\CandidatePortalAccounts\Pages\ListCandidatePortalAccounts;
use App\Filament\Resources\CandidatePortalAccounts\Pages\ViewCandidatePortalAccount;
use App\Filament\Resources\CandidatePortalAccounts\Schemas\CandidatePortalAccountInfolist;
use App\Filament\Resources\CandidatePortalAccounts\Tables\CandidatePortalAccountsTable;
use App\Filament\Resources\Candidates\Schemas\CandidatePicker;
use App\Models\CandidatePortalAccount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Who has candidate portal access. Invitations are sent from the Candidate 360 page; here access
 * can be reviewed, re-sent or revoked. Scoped to candidates the user can see.
 */
class CandidatePortalAccountResource extends Resource
{
    protected static ?string $model = CandidatePortalAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Candidate Experience';

    protected static ?string $navigationLabel = 'Portal Access';

    protected static ?string $modelLabel = 'portal account';

    public static function infolist(Schema $schema): Schema
    {
        return CandidatePortalAccountInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CandidatePortalAccountsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('candidate_id', CandidatePicker::selectableCandidates()->select('candidates.id'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCandidatePortalAccounts::route('/'),
            'view' => ViewCandidatePortalAccount::route('/{record}'),
        ];
    }
}
