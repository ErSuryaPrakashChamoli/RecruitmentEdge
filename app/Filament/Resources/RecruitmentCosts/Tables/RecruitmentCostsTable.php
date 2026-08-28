<?php

namespace App\Filament\Resources\RecruitmentCosts\Tables;

use App\Enums\RecruitmentCostType;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RecruitmentCostsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('cost_type')
                    ->badge()
                    ->formatStateUsing(fn (RecruitmentCostType $state) => $state->label()),
                TextColumn::make('amount')
                    ->money('INR')
                    ->sortable(),
                TextColumn::make('incurred_on')
                    ->date()
                    ->sortable(),
                TextColumn::make('requisition.code')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('department.name')
                    ->placeholder('—')
                    ->searchable(),
            ])
            ->defaultSort('incurred_on', 'desc')
            ->filters([
                SelectFilter::make('cost_type')
                    ->options(collect(RecruitmentCostType::cases())->mapWithKeys(fn (RecruitmentCostType $t) => [$t->value => $t->label()])),
                SelectFilter::make('department')
                    ->relationship('department', 'name'),
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
