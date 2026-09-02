<?php

namespace App\Filament\Resources\AiUsageLogs\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AiUsageLogsTable
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
                TextColumn::make('provider')
                    ->badge(),
                TextColumn::make('model'),
                TextColumn::make('request_type')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label() ?? $state),
                TextColumn::make('input_tokens')
                    ->label('In'),
                TextColumn::make('output_tokens')
                    ->label('Out'),
                TextColumn::make('cached_tokens')
                    ->label('Cached'),
                TextColumn::make('latency_ms')
                    ->label('Latency (ms)'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => $state === 'success' ? 'success' : 'danger'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('request_type')
                    ->options([
                        'chat' => 'Chat',
                        'embedding' => 'Embedding',
                        'tool_call' => 'Tool Call',
                        'web_search' => 'Web Search',
                    ]),
                SelectFilter::make('status')
                    ->options(['success' => 'Success', 'error' => 'Error']),
            ]);
    }
}
