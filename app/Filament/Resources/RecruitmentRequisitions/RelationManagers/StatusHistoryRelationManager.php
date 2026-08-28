<?php

namespace App\Filament\Resources\RecruitmentRequisitions\RelationManagers;

use App\Enums\RequisitionStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only: this is an immutable audit trail written only by RequisitionApprovalService, so no
 * create/edit/delete actions are exposed here.
 */
class StatusHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'statusHistory';

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
                    ->formatStateUsing(fn (?RequisitionStatus $state) => $state?->label() ?? '—'),
                TextColumn::make('to_status')
                    ->formatStateUsing(fn (RequisitionStatus $state) => $state->label())
                    ->badge(),
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
