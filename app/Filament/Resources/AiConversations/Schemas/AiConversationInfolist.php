<?php

namespace App\Filament\Resources\AiConversations\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AiConversationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Conversation')
                ->columns(3)
                ->schema([
                    TextEntry::make('user.name')
                        ->label('User')
                        ->placeholder('—'),
                    TextEntry::make('title')
                        ->placeholder('—'),
                    TextEntry::make('status')
                        ->badge()
                        ->formatStateUsing(fn ($state) => $state?->label() ?? $state),
                    TextEntry::make('context_type')
                        ->label('Context')
                        ->formatStateUsing(fn ($state, $record) => str($state)->headline().($record->context_id ? " #{$record->context_id}" : ''))
                        ->placeholder('General'),
                    TextEntry::make('created_at')
                        ->label('Started')
                        ->dateTime(),
                    TextEntry::make('last_message_at')
                        ->label('Last message')
                        ->dateTime()
                        ->placeholder('—'),
                ]),
            Section::make('Transcript')
                ->description('Every message in order, including tool calls the model requested and the results they returned.')
                ->schema([
                    ViewEntry::make('transcript')
                        ->hiddenLabel()
                        ->view('filament.components.ai-conversation-transcript')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
