<?php

namespace App\Filament\Resources\RecruitmentManualActivities\Tables;

use App\Enums\TargetMetric;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RecruitmentManualActivitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('activity_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('recruiter.first_name')
                    ->label('Recruiter')
                    ->formatStateUsing(fn ($record) => $record->recruiter->fullName()),
                TextColumn::make('metric')
                    ->badge()
                    ->formatStateUsing(fn (TargetMetric $state) => $state->label()),
                TextColumn::make('count')
                    ->sortable(),
                TextColumn::make('remarks')
                    ->limit(50),
            ])
            ->defaultSort('activity_date', 'desc')
            ->filters([
                SelectFilter::make('metric')
                    ->options(collect(TargetMetric::cases())->mapWithKeys(fn (TargetMetric $m) => [$m->value => $m->label()])),
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
