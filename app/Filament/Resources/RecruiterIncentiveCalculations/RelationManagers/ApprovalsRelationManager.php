<?php

namespace App\Filament\Resources\RecruiterIncentiveCalculations\RelationManagers;

use App\Enums\IncentiveCalculationStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only: an immutable audit trail written only by IncentiveApprovalService.
 */
class ApprovalsRelationManager extends RelationManager
{
    protected static string $relationship = 'approvals';

    protected static ?string $title = 'Status History';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('to_status')
            ->columns([
                TextColumn::make('from_status')
                    ->formatStateUsing(fn (?IncentiveCalculationStatus $state) => $state?->label() ?? '—'),
                TextColumn::make('to_status')
                    ->badge()
                    ->formatStateUsing(fn (IncentiveCalculationStatus $state) => $state->label()),
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
