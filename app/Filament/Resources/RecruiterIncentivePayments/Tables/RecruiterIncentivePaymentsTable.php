<?php

namespace App\Filament\Resources\RecruiterIncentivePayments\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RecruiterIncentivePaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('calculation.employee.first_name')
                    ->label('Recruiter')
                    ->formatStateUsing(fn ($record) => $record->calculation->employee->fullName())
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('calculation.candidate.full_name')
                    ->label('Candidate')
                    ->searchable(),
                TextColumn::make('amount')
                    ->money('INR')
                    ->sortable(),
                TextColumn::make('payment_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('payment_reference')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('paidBy.first_name')
                    ->label('Paid By')
                    ->formatStateUsing(fn ($record) => $record->paidBy?->fullName() ?? '—'),
            ])
            ->defaultSort('payment_date', 'desc')
            ->recordActions([])
            ->toolbarActions([]);
    }
}
