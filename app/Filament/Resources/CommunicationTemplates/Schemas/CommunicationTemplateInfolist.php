<?php

namespace App\Filament\Resources\CommunicationTemplates\Schemas;

use App\Enums\CommunicationChannel;
use App\Enums\TemplateStatus;
use App\Models\CommunicationTemplateVersion;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CommunicationTemplateInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Template')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('key'),
                        TextEntry::make('channel')->formatStateUsing(fn (CommunicationChannel $state) => $state->label()),
                        TextEntry::make('status')->badge()->formatStateUsing(fn (TemplateStatus $state) => $state->label())->color(fn (TemplateStatus $state) => $state->color()),
                        TextEntry::make('language'),
                        TextEntry::make('version')->prefix('v'),
                        TextEntry::make('provider_template')->placeholder('—'),
                        TextEntry::make('updatedBy.first_name')->label('Last edited by')->formatStateUsing(fn ($record) => $record->updatedBy?->fullName())->placeholder('—'),
                        TextEntry::make('subject')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('body')->extraAttributes(['class' => 'whitespace-pre-line'])->columnSpanFull(),
                    ]),
                Section::make('Version history')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        RepeatableEntry::make('versions')
                            ->hiddenLabel()
                            ->schema([
                                TextEntry::make('version')->prefix('v')->label('Version'),
                                TextEntry::make('created_at')->dateTime(),
                                TextEntry::make('body')->state(fn (CommunicationTemplateVersion $record) => trim(($record->subject ? "Subject: {$record->subject}\n" : '').$record->body))->extraAttributes(['class' => 'whitespace-pre-line'])->columnSpanFull(),
                            ])
                            ->columns(2),
                    ]),
            ]);
    }
}
