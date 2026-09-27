<?php

namespace App\Filament\Resources\RecruitmentRejectionReasons\Tables;

use App\Enums\RejectionCategory;
use App\Filament\Actions\MasterDataLifecycleActions;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class RecruitmentRejectionReasonsTable
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
                TextColumn::make('category')
                    ->badge()
                    ->formatStateUsing(fn (RejectionCategory $state) => $state->label()),
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
                SelectFilter::make('category')
                    ->options(collect(RejectionCategory::cases())->mapWithKeys(fn (RejectionCategory $c) => [$c->value => $c->label()])),
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
