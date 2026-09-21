<?php

namespace App\Filament\Resources\OfferLetterTemplates\Schemas;

use App\Enums\OfferLetterTemplateFormat;
use App\Filament\Resources\OfferLetterTemplates\Actions\OfferLetterTemplateActions;
use App\Models\OfferLetterTemplate;
use App\Services\OfferLetterRenderer;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class OfferLetterTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Template')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Toggle::make('is_default')
                            ->label('Default template')
                            ->helperText('Used for every offer that has not picked or customised its own letter. Only one template can be the default.')
                            ->inline(false),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->disabled(fn (?OfferLetterTemplate $record): bool => (bool) $record?->is_system)
                            ->helperText(fn (?OfferLetterTemplate $record): ?string => $record?->is_system
                                ? 'The standard offer letter is always active and cannot be deleted.'
                                : null)
                            ->inline(false),
                        Radio::make('format')
                            ->label('Maintained as')
                            ->options(collect(OfferLetterTemplateFormat::cases())->mapWithKeys(fn (OfferLetterTemplateFormat $format) => [$format->value => $format->label()]))
                            ->default(OfferLetterTemplateFormat::RichText->value)
                            ->disabled(fn (?OfferLetterTemplate $record): bool => (bool) $record?->is_system)
                            ->required()
                            ->live()
                            ->columnSpanFull(),
                    ]),
                RichEditor::make('body')
                    ->label('Letter')
                    ->mergeTags(OfferLetterRenderer::MERGE_TAGS)
                    ->helperText('Insert merge tags such as Candidate name, Designation or Offered CTC from the editor — each offer fills them in when its letter is generated.')
                    ->visible(fn (Get $get): bool => self::format($get) === OfferLetterTemplateFormat::RichText)
                    ->required(fn (Get $get): bool => self::format($get) === OfferLetterTemplateFormat::RichText)
                    ->columnSpanFull(),
                OfferLetterTemplateActions::wordFileUpload('file_path')
                    ->helperText('Write the letter in Word using placeholders: '.OfferLetterTemplateActions::placeholderList().'. Later, use "Download Word File" to edit it and "Upload New Version" to replace it.')
                    ->visible(fn (Get $get): bool => self::format($get) === OfferLetterTemplateFormat::Word)
                    ->required(fn (Get $get): bool => self::format($get) === OfferLetterTemplateFormat::Word)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * The form state holds either the enum (a loaded record) or its raw value (a user's choice).
     */
    private static function format(Get $get): ?OfferLetterTemplateFormat
    {
        $state = $get('format');

        return $state instanceof OfferLetterTemplateFormat ? $state : OfferLetterTemplateFormat::tryFrom((string) $state);
    }
}
