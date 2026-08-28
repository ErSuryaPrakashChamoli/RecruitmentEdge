<?php

namespace App\Filament\Resources\Employees\Tables;

use App\Enums\EmployeeStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class EmployeesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee_code')
                    ->label('Code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('first_name')
                    ->label('Name')
                    ->formatStateUsing(fn ($record) => $record->fullName())
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('department.name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('designation.name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('reportsTo.first_name')
                    ->label('Reports To')
                    ->formatStateUsing(fn ($record) => $record->reportsTo?->fullName() ?? '—'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (EmployeeStatus $state) => $state->label())
                    ->color(fn (EmployeeStatus $state) => match ($state) {
                        EmployeeStatus::Active => 'success',
                        EmployeeStatus::Inactive => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('department')
                    ->relationship('department', 'name'),
                SelectFilter::make('status')
                    ->options(collect(EmployeeStatus::cases())->mapWithKeys(fn (EmployeeStatus $s) => [$s->value => $s->label()])),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
