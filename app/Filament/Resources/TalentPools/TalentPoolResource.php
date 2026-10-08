<?php

namespace App\Filament\Resources\TalentPools;

use App\Filament\Resources\TalentPools\Pages\CreateTalentPool;
use App\Filament\Resources\TalentPools\Pages\EditTalentPool;
use App\Filament\Resources\TalentPools\Pages\ListTalentPools;
use App\Filament\Resources\TalentPools\Pages\ViewTalentPool;
use App\Filament\Resources\TalentPools\RelationManagers\MembersRelationManager;
use App\Filament\Resources\TalentPools\Schemas\TalentPoolForm;
use App\Filament\Resources\TalentPools\Schemas\TalentPoolInfolist;
use App\Filament\Resources\TalentPools\Tables\TalentPoolsTable;
use App\Models\TalentPool;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Phase 4 Talent Pools. Lists are scoped by TalentPool::scopeVisibleTo (hierarchy + pool
 * visibility) and single records by TalentPoolPolicy, per the project's "query scope AND policy"
 * rule. Membership writes go through TalentPoolService.
 */
class TalentPoolResource extends Resource
{
    protected static ?string $model = TalentPool::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Recruitment';

    protected static ?string $navigationLabel = 'Talent Pools';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return TalentPoolForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TalentPoolInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TalentPoolsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return parent::getEloquentQuery()->visibleTo($user);
    }

    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTalentPools::route('/'),
            'create' => CreateTalentPool::route('/create'),
            'view' => ViewTalentPool::route('/{record}'),
            'edit' => EditTalentPool::route('/{record}/edit'),
        ];
    }
}
