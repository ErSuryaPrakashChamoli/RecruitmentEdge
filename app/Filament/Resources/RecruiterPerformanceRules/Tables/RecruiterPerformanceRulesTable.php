<?php

namespace App\Filament\Resources\RecruiterPerformanceRules\Tables;

use App\Enums\TargetMetric;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RecruiterPerformanceRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('metric')
                    ->badge()
                    ->formatStateUsing(fn (TargetMetric $state) => $state->label()),
                TextColumn::make('weightage')
                    ->label('Weightage (%)')
                    ->sortable(),
                TextColumn::make('effective_from')
                    ->date()
                    ->sortable(),
                TextColumn::make('effective_to')
                    ->date()
                    ->placeholder('Ongoing'),
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
