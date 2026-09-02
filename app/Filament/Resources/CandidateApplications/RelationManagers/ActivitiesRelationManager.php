<?php

namespace App\Filament\Resources\CandidateApplications\RelationManagers;

use App\Enums\ActivityOutcome;
use App\Enums\ActivityType;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only: the structured contact-activity log (RecruitmentDailyActivity), written only via the
 * Daily Activities resource / RecruiterDailyMetricsService's authoritative source, never here.
 */
class ActivitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    protected static ?string $title = 'Activities';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('activity_type')
            ->columns([
                TextColumn::make('activity_type')
                    ->badge()
                    ->formatStateUsing(fn (ActivityType $state) => $state->label()),
                TextColumn::make('outcome')
                    ->badge()
                    ->formatStateUsing(fn (?ActivityOutcome $state) => $state?->label() ?? '—')
                    ->color(fn (?ActivityOutcome $state) => $state?->isConnected() ? 'success' : 'gray'),
                TextColumn::make('activity_datetime')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('createdBy.first_name')
                    ->label('Logged By')
                    ->formatStateUsing(fn ($record) => $record->createdBy?->fullName() ?? '—'),
                TextColumn::make('remarks')
                    ->wrap(),
            ])
            ->defaultSort('activity_datetime', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
