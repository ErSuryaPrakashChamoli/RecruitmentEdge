<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Models\Role;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Permission;

/**
 * Phase 8.4: the permission list is a plain option list handed to RoleAssignmentService, which
 * refuses permissions the editor does not hold and any change to a protected role's permissions.
 * A role's key (its identity) is never editable.
 */
class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->scopedUnique(ignoreRecord: true, modifyQueryUsing: fn (Builder $query) => $query->forCurrentTenant()),
                TextInput::make('key')
                    ->label('Key')
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('The role\'s permanent identity. Renaming the role never changes it.')
                    ->visibleOn(['edit', 'view']),
                CheckboxList::make('permissions')
                    ->options(fn (): array => Permission::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->disabled(fn (?Role $record): bool => (bool) $record?->is_protected)
                    ->helperText(fn (?Role $record): string => $record?->is_protected
                        ? 'This role is protected: its permissions cannot be changed here.'
                        : 'You can only give a role permissions you hold yourself.')
                    ->columns(3)
                    ->bulkToggleable()
                    ->searchable(),
            ]);
    }
}
