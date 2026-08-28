<?php

namespace App\Filament\Resources\RecruitmentDailyActivities\Tables;

use App\Enums\ActivityOutcome;
use App\Enums\ActivityType;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RecruitmentDailyActivitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('activity_datetime')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('recruiter.first_name')
                    ->label('Recruiter')
                    ->formatStateUsing(fn ($record) => $record->recruiter->fullName())
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('candidate.full_name')
                    ->label('Candidate')
                    ->searchable(),
                TextColumn::make('activity_type')
                    ->badge()
                    ->formatStateUsing(fn (ActivityType $state) => $state->label()),
                TextColumn::make('outcome')
                    ->badge()
                    ->formatStateUsing(fn (?ActivityOutcome $state) => $state?->label() ?? '—')
                    ->color(fn (?ActivityOutcome $state) => $state?->isConnected() ? 'success' : 'gray'),
                TextColumn::make('remarks')
                    ->limit(50)
                    ->toggleable(),
            ])
            ->defaultSort('activity_datetime', 'desc')
            ->filters([
                SelectFilter::make('activity_type')
                    ->options(collect(ActivityType::cases())->mapWithKeys(fn (ActivityType $t) => [$t->value => $t->label()])),
                SelectFilter::make('outcome')
                    ->options(collect(ActivityOutcome::cases())->mapWithKeys(fn (ActivityOutcome $o) => [$o->value => $o->label()])),
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
