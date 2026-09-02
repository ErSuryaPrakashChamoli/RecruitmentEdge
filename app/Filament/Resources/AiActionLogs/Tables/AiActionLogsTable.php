<?php

namespace App\Filament\Resources\AiActionLogs\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AiActionLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('User')
                    ->placeholder('—'),
                TextColumn::make('tool_name')
                    ->searchable(),
                TextColumn::make('risk_level')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label() ?? $state)
                    ->color(fn ($state) => match ($state?->value ?? $state) {
                        'high_impact' => 'danger',
                        'external', 'write' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('entity_type')
                    ->placeholder('—'),
                TextColumn::make('result_summary')
                    ->limit(60)
                    ->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'executed' => 'success',
                        'rejected' => 'gray',
                        'failed' => 'danger',
                        default => 'warning',
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('risk_level')
                    ->options([
                        'read' => 'Read',
                        'recommend' => 'Recommend',
                        'write' => 'Write',
                        'external' => 'External',
                        'high_impact' => 'High Impact',
                    ]),
                SelectFilter::make('status')
                    ->options([
                        'executed' => 'Executed',
                        'rejected' => 'Rejected',
                        'failed' => 'Failed',
                    ]),
            ]);
    }
}
