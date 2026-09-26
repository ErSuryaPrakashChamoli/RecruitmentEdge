<?php

namespace App\Filament\Resources\EmployeeSeparations\Tables;

use App\Enums\SeparationReason;
use App\Models\EmployeeSeparation;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class EmployeeSeparationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.employee_code')
                    ->label('Employee')
                    ->formatStateUsing(fn (EmployeeSeparation $record): string => ($record->employee?->fullName() ?? '—').' ('.($record->employee?->employee_code ?? '—').')')
                    ->searchable(),
                TextColumn::make('separation_date')
                    ->label('Last working day')
                    ->date()
                    ->sortable(),
                TextColumn::make('separation_reason')
                    ->label('Reason')
                    ->badge()
                    ->formatStateUsing(fn (EmployeeSeparation $record): string => $record->separation_reason->label()),
                TextColumn::make('updated_at')
                    ->label('Last changed')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('separation_date', 'desc')
            ->filters([
                SelectFilter::make('separation_reason')
                    ->label('Reason')
                    ->options(SeparationReason::options()),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->emptyStateHeading('No separations recorded')
            ->emptyStateDescription('Record an employee\'s last working day here. The Outcome Loop uses it as the only reliable source for attrition.');
    }
}
