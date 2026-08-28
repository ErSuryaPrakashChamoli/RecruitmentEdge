<?php

namespace App\Filament\Resources\RecruiterIncentiveCalculations\RelationManagers;

use App\Enums\IncentiveAdjustmentType;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only: adjustments/reversals are created only via IncentiveApprovalService, never edited or
 * deleted here (Section 28).
 */
class AdjustmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'adjustments';

    protected static ?string $title = 'Adjustments';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reason')
            ->columns([
                TextColumn::make('adjustment_type')
                    ->badge()
                    ->formatStateUsing(fn (IncentiveAdjustmentType $state) => $state->label()),
                TextColumn::make('amount_delta')
                    ->money('INR'),
                TextColumn::make('reason')
                    ->wrap(),
                TextColumn::make('createdBy.first_name')
                    ->label('By')
                    ->formatStateUsing(fn ($record) => $record->createdBy?->fullName() ?? 'System'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
