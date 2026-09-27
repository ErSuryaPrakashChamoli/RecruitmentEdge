<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\AccessState;
use App\Enums\EmployeeStatus;
use App\Filament\Resources\Users\MfaStatus;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('employee.employee_code')
                    ->label('Employee')
                    ->searchable(),
                TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge(),
                // Phase 8.4: employment, access and MFA at a glance.
                TextColumn::make('employee.status')
                    ->label('Employment')
                    ->badge()
                    ->formatStateUsing(fn (?EmployeeStatus $state): string => $state?->label() ?? '—')
                    ->color(fn (?EmployeeStatus $state): string => $state?->color() ?? 'gray'),
                TextColumn::make('access_status')
                    ->label('Access')
                    ->badge()
                    ->formatStateUsing(fn (AccessState $state): string => $state->label())
                    ->color(fn (AccessState $state): string => $state->color()),
                TextColumn::make('mfa')
                    ->label('MFA')
                    ->badge()
                    ->state(fn (User $record): string => MfaStatus::of($record))
                    ->color(fn (string $state): string => MfaStatus::color($state)),
                TextColumn::make('last_login_at')
                    ->label('Last sign-in')
                    ->since()
                    ->placeholder('Never')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('roles')
                    ->relationship('roles', 'name'),
                SelectFilter::make('access_status')
                    ->label('Access')
                    ->options(collect(AccessState::cases())->mapWithKeys(fn (AccessState $state) => [$state->value => $state->label()])->all()),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
