<?php

namespace App\Filament\Resources\RecruitmentIncentiveRules\Tables;

use App\Enums\IncentiveTriggerEvent;
use App\Enums\TargetMetric;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RecruitmentIncentiveRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('trigger_event')
                    ->badge()
                    ->formatStateUsing(fn (IncentiveTriggerEvent $state) => $state->label()),
                TextColumn::make('achievement_metric')
                    ->badge()
                    ->formatStateUsing(fn (?TargetMetric $state) => $state?->label() ?? 'Flat amount'),
                TextColumn::make('retention_days')
                    ->placeholder('None'),
                TextColumn::make('slabs_count')
                    ->label('Slabs')
                    ->counts('slabs'),
                TextColumn::make('effective_from')
                    ->date()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('trigger_event')
                    ->options(collect(IncentiveTriggerEvent::cases())->mapWithKeys(fn (IncentiveTriggerEvent $e) => [$e->value => $e->label()])),
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
