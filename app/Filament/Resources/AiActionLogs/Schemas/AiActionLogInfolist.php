<?php

namespace App\Filament\Resources\AiActionLogs\Schemas;

use App\Models\AiActionLog;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;

class AiActionLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Action')
                ->columns(3)
                ->schema([
                    TextEntry::make('created_at')
                        ->dateTime(),
                    TextEntry::make('user.name')
                        ->label('User')
                        ->placeholder('—'),
                    TextEntry::make('conversation.title')
                        ->label('Conversation')
                        ->placeholder('—'),
                    TextEntry::make('tool_name'),
                    TextEntry::make('risk_level')
                        ->badge()
                        ->formatStateUsing(fn ($state) => $state?->label() ?? $state),
                    TextEntry::make('status')
                        ->badge(),
                    TextEntry::make('entity_type')
                        ->placeholder('—'),
                    TextEntry::make('entity_ids')
                        ->label('Entity IDs')
                        ->state(fn (AiActionLog $record) => filled($record->entity_ids) ? implode(', ', (array) $record->entity_ids) : null)
                        ->placeholder('—'),
                    TextEntry::make('result_summary')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),
            Section::make('Input parameters')
                ->schema([
                    TextEntry::make('input_json')
                        ->hiddenLabel()
                        ->state(fn (AiActionLog $record) => self::prettyJson($record->input))
                        ->fontFamily(FontFamily::Mono)
                        ->extraAttributes(['class' => 'whitespace-pre-wrap'])
                        ->copyable()
                        ->columnSpanFull(),
                ]),
            Section::make('Full result')
                ->collapsed()
                ->schema([
                    TextEntry::make('output_json')
                        ->hiddenLabel()
                        ->state(fn (AiActionLog $record) => self::prettyJson($record->output))
                        ->fontFamily(FontFamily::Mono)
                        ->extraAttributes(['class' => 'whitespace-pre-wrap'])
                        ->placeholder('Not recorded (logged before full results were stored).')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * @param  array<mixed>|null  $value
     */
    private static function prettyJson(?array $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
