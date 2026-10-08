<?php

namespace App\Filament\Resources\EmployeeReferrals;

use App\Filament\Resources\EmployeeReferrals\Pages\CreateEmployeeReferral;
use App\Filament\Resources\EmployeeReferrals\Pages\EditEmployeeReferral;
use App\Filament\Resources\EmployeeReferrals\Pages\ListEmployeeReferrals;
use App\Filament\Resources\EmployeeReferrals\Pages\ViewEmployeeReferral;
use App\Filament\Resources\EmployeeReferrals\Schemas\EmployeeReferralForm;
use App\Filament\Resources\EmployeeReferrals\Schemas\EmployeeReferralInfolist;
use App\Filament\Resources\EmployeeReferrals\Tables\EmployeeReferralsTable;
use App\Models\EmployeeReferral;
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
 * Phase 4 Employee Referrals. Lists are scoped by EmployeeReferral::scopeVisibleTo and records by
 * EmployeeReferralPolicy; every write goes through ReferralService.
 */
class EmployeeReferralResource extends Resource
{
    protected static ?string $model = EmployeeReferral::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Recruitment';

    protected static ?string $navigationLabel = 'Employee Referrals';

    protected static ?string $modelLabel = 'referral';

    protected static ?string $recordTitleAttribute = 'referral_code';

    public static function form(Schema $schema): Schema
    {
        return EmployeeReferralForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return EmployeeReferralInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmployeeReferralsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return parent::getEloquentQuery()->visibleTo($user);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployeeReferrals::route('/'),
            'create' => CreateEmployeeReferral::route('/create'),
            'view' => ViewEmployeeReferral::route('/{record}'),
            'edit' => EditEmployeeReferral::route('/{record}/edit'),
        ];
    }
}
