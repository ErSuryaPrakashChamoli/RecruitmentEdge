<?php

namespace App\Filament\Resources\CommunicationTemplates\Schemas;

use App\Enums\CommunicationChannel;
use App\Enums\TemplateStatus;
use App\Services\Communication\TemplateRenderer;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Throwable;

class CommunicationTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Template')
                    ->columns(3)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255),
                        TextInput::make('key')
                            ->label('Purpose key')
                            ->helperText('Automatic messages look templates up by key, e.g. interview_scheduled.')
                            ->maxLength(80)
                            ->alphaDash()
                            ->disabledOn('edit')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                        Select::make('channel')
                            ->options(CommunicationChannel::options(sendableOnly: true))
                            ->required()
                            ->live()
                            ->disabledOn('edit')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                        Select::make('language')->options(['en' => 'English', 'hi' => 'Hindi'])->default('en')->required()->disabledOn('edit')->dehydrated(fn (string $operation): bool => $operation === 'create'),
                        Select::make('status')->options(TemplateStatus::options())->default(TemplateStatus::Draft->value)->required(),
                        TextInput::make('provider_template')
                            ->label('Provider template name')
                            ->helperText('WhatsApp: the Meta-approved template name. Variables are sent as its body parameters, in order.')
                            ->visible(fn (Get $get): bool => $get('channel') === CommunicationChannel::WhatsApp->value)
                            ->maxLength(255),
                        Textarea::make('description')->columnSpanFull(),
                    ]),
                Section::make('Content')
                    ->description('Use variables like {{candidate.first_name}}. Only the listed variables are allowed; changing the wording creates a new version.')
                    ->schema([
                        TextInput::make('subject')
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('channel') === CommunicationChannel::Email->value)
                            ->required(fn (Get $get): bool => $get('channel') === CommunicationChannel::Email->value)
                            ->live(debounce: 600),
                        Textarea::make('body')->rows(8)->required()->live(debounce: 600),
                        TextEntry::make('preview')
                            ->label('Preview (sample values)')
                            ->state(function (Get $get): string {
                                try {
                                    $renderer = app(TemplateRenderer::class);

                                    return (filled($get('subject')) ? 'Subject: '.$renderer->preview((string) $get('subject'))."\n\n" : '').$renderer->preview((string) $get('body'));
                                } catch (Throwable $e) {
                                    return $e->getMessage();
                                }
                            })
                            ->extraAttributes(['class' => 'whitespace-pre-line']),
                        TextEntry::make('variables')
                            ->label('Available variables')
                            ->state(collect(TemplateRenderer::VARIABLES)->map(fn (string $label, string $var) => '{{'.$var.'}} — '.$label)->values()->all())
                            ->listWithLineBreaks()
                            ->bulleted(),
                    ]),
            ]);
    }
}
