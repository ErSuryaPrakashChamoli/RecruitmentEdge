<?php

namespace App\Filament\Resources\Roles\Tables;

use App\Filament\Resources\Roles\RoleResource;
use App\Models\Role;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Role $record): ?string => $record->is_protected ? 'Protected' : null),
                TextColumn::make('key')
                    ->placeholder('—'),
                TextColumn::make('permissions_count')
                    ->label('Permissions')
                    ->counts('permissions'),
                TextColumn::make('users_count')
                    ->label('Users')
                    ->counts('users'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->using(fn (Role $record) => RoleResource::deleteThroughService($record)),
            ]);
    }
}
