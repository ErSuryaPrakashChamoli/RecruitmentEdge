<?php

namespace App\Filament\Resources\Roles;

use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\Pages\ViewRole;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use App\Filament\Resources\Roles\Tables\RolesTable;
use App\Models\Role;
use App\Models\User;
use App\Services\Identity\RoleAssignmentService;
use BackedEnum;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * SaaS-1: not scoped by Filament's tenancy. Filament would add a global scope to the Role model,
     * which would also limit the roles spatie loads when it rebuilds its permission cache — one
     * cache for every tenant. The tenant boundary is getEloquentQuery() and RolePolicy instead.
     */
    protected static bool $isScopedToTenant = false;

    /**
     * SaaS-1: the current tenant's roles only (Filament's tenancy adds the same condition; the
     * explicit one keeps the boundary when the query is used outside a panel request).
     *
     * @return Builder<Role>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->forCurrentTenant();
    }

    public static function form(Schema $schema): Schema
    {
        return RoleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RolesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    /**
     * Phase 8.4: deleting a role goes through RoleAssignmentService (protected roles refused,
     * audited with what the role granted).
     */
    public static function deleteThroughService(Role $role): bool
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            app(RoleAssignmentService::class)->deleteRole($role, $actor);
        } catch (DomainException $e) {
            Notification::make()->title('Role could not be deleted')->body($e->getMessage())->danger()->persistent()->send();

            throw new Halt;
        }

        return true;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'view' => ViewRole::route('/{record}'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
