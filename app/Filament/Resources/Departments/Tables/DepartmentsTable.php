<?php

namespace App\Filament\Resources\Departments\Tables;

use App\Filament\Actions\MasterDataLifecycleActions;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class DepartmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('designations_count')
                    ->label('Designations')
                    ->counts('designations'),
                TextColumn::make('lifecycle_state')
                    ->label('Status')
                    ->badge()
                    ->state(fn ($record): string => $record->lifecycleState())
                    ->color(fn (string $state): string => match ($state) {
                        'Active' => 'success',
                        'Inactive' => 'warning',
                        default => 'gray',
                    }),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                ...MasterDataLifecycleActions::all(),
            ])
            // Phase 8.6: no bulk archive/restore/delete — each change needs its own reason and in-use check.
            ->toolbarActions([]);
    }
}
