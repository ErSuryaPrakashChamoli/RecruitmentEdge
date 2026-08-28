<?php

namespace App\Filament\Resources\RecruitmentDailyTargets\Tables;

use App\Enums\TargetMetric;
use App\Enums\TargetPeriodType;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RecruitmentDailyTargetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('scope')
                    ->label('Scope')
                    ->state(fn ($record) => $record->employee?->fullName()
                        ?? $record->designation?->name
                        ?? $record->department?->name
                        ?? '—'),
                TextColumn::make('metric')
                    ->badge()
                    ->formatStateUsing(fn (TargetMetric $state) => $state->label()),
                TextColumn::make('period_type')
                    ->badge()
                    ->formatStateUsing(fn (TargetPeriodType $state) => $state->label()),
                TextColumn::make('target_value')
                    ->sortable(),
                TextColumn::make('effective_from')
                    ->date()
                    ->sortable(),
                TextColumn::make('effective_to')
                    ->date()
                    ->placeholder('Ongoing'),
            ])
            ->filters([
                SelectFilter::make('metric')
                    ->options(collect(TargetMetric::cases())->mapWithKeys(fn (TargetMetric $m) => [$m->value => $m->label()])),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
