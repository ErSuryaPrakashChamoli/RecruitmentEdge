<?php

namespace App\Filament\Resources\CandidateApplications\RelationManagers;

use App\Enums\InterviewMode;
use App\Enums\InterviewResult;
use App\Enums\InterviewStatus;
use App\Models\Interview;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only summary for the application's 360 view — full interview scheduling/actions stay on
 * the dedicated Interviews resource (InterviewsTable), not duplicated here.
 */
class InterviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'interviews';

    protected static ?string $title = 'Interviews';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('round_number')
            ->columns([
                TextColumn::make('round_number')
                    ->label('Round'),
                TextColumn::make('interviewer.first_name')
                    ->label('Interviewer')
                    ->formatStateUsing(fn (Interview $record) => $record->interviewer?->fullName() ?? '—'),
                TextColumn::make('scheduled_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('mode')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (InterviewMode $state) => $state->label()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (InterviewStatus $state) => $state->label())
                    ->color(fn (InterviewStatus $state) => $state->color()),
                TextColumn::make('result')
                    ->badge()
                    ->formatStateUsing(fn (?InterviewResult $state) => $state?->label() ?? '—')
                    ->color(fn (?InterviewResult $state) => $state?->color() ?? 'gray'),
            ])
            ->defaultSort('scheduled_at', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
