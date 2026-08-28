<?php

namespace App\Filament\Resources\RecruiterIncentiveCalculations\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only: payments are recorded only via IncentiveApprovalService::pay().
 */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Payments';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('payment_reference')
            ->columns([
                TextColumn::make('amount')
                    ->money('INR'),
                TextColumn::make('payment_date')
                    ->date(),
                TextColumn::make('payment_reference')
                    ->placeholder('—'),
                TextColumn::make('paidBy.first_name')
                    ->label('Paid By')
                    ->formatStateUsing(fn ($record) => $record->paidBy?->fullName() ?? '—'),
            ])
            ->defaultSort('payment_date', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
