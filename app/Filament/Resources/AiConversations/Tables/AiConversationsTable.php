<?php

namespace App\Filament\Resources\AiConversations\Tables;

use App\Enums\AiConversationStatus;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AiConversationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('User')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('title')
                    ->searchable()
                    ->limit(60)
                    ->wrap(),
                TextColumn::make('context_type')
                    ->label('Context')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => filled($state) ? str($state)->headline() : null)
                    ->placeholder('General'),
                TextColumn::make('messages_count')
                    ->label('Messages')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label() ?? $state)
                    ->color(fn ($state) => ($state?->value ?? $state) === 'active' ? 'success' : 'gray'),
                TextColumn::make('created_at')
                    ->label('Started')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('last_message_at')
                    ->label('Last message')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('last_message_at', 'desc')
            ->filters([
                SelectFilter::make('user')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->options(collect(AiConversationStatus::cases())->mapWithKeys(fn (AiConversationStatus $status) => [$status->value => $status->label()])),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
