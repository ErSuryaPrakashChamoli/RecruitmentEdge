<?php

namespace App\Filament\Resources\RecruitmentIncentiveRules\Tables;

use App\Enums\IncentivePayoutType;
use App\Enums\IncentiveTriggerEvent;
use App\Models\RecruitmentIncentiveRule;
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
                TextColumn::make('payout_type')
                    ->label('Payout')
                    ->badge()
                    ->formatStateUsing(fn (IncentivePayoutType $state, RecruitmentIncentiveRule $record): string => match ($state) {
                        IncentivePayoutType::Fixed => 'Fixed ₹'.number_format((float) $record->fixed_amount, 2).' per '.$record->trigger_event->occurrenceNoun(),
                        IncentivePayoutType::SlabByCount => 'Slab by '.$record->trigger_event->countNoun().' · '.$record->slab_upgrade_mode->label(),
                        IncentivePayoutType::SlabByAchievement => 'Slab by '.($record->achievement_metric?->label() ?? 'achievement').' % · '.$record->slab_upgrade_mode->label(),
                    }),
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
                SelectFilter::make('payout_type')
                    ->options(collect(IncentivePayoutType::cases())->mapWithKeys(fn (IncentivePayoutType $type) => [$type->value => $type->label()])),
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
