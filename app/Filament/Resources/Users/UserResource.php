<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use App\Services\HierarchyService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Phase 8.4: logins inside the viewer's hierarchy (everything with hierarchy.view-all), with the
     * relations the access columns need loaded up front.
     *
     * @return Builder<User>
     */
    public static function getEloquentQuery(): Builder
    {
        $viewer = auth()->user();
        $visible = $viewer instanceof User ? app(HierarchyService::class)->visibleEmployeeIdsFor($viewer) : collect();

        return parent::getEloquentQuery()
            ->with(['employee' => fn ($query) => $query->withTrashed(), 'roles.permissions', 'permissions'])
            ->when($visible !== null, fn (Builder $query) => $query->whereIn('employee_id', $visible));
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
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
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'view' => ViewUser::route('/{record}'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
