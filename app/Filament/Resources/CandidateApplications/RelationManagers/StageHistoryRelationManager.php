<?php

namespace App\Filament\Resources\CandidateApplications\RelationManagers;

use App\Enums\CandidateStage;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only: an immutable audit trail written only by StageTransitionService.
 */
class StageHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'stageHistory';

    protected static ?string $title = 'Stage History';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('new_stage')
            ->columns([
                TextColumn::make('previous_stage')
                    ->formatStateUsing(fn (?CandidateStage $state) => $state?->label() ?? '—'),
                TextColumn::make('new_stage')
                    ->badge()
                    ->formatStateUsing(fn (CandidateStage $state) => $state->label()),
                TextColumn::make('changedBy.first_name')
                    ->label('Changed By')
                    ->formatStateUsing(fn ($record) => $record->changedBy?->fullName() ?? 'System'),
                TextColumn::make('remarks')
                    ->wrap(),
                TextColumn::make('created_at')
                    ->label('Changed At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
