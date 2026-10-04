<?php

namespace App\Filament\Resources\AiDocuments\Schemas;

use App\Services\Tenancy\TenantStorage;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class AiDocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255),
                Select::make('category')
                    ->options([
                        'policy' => 'Policy',
                        'sop' => 'SOP',
                        'guideline' => 'Guideline',
                        'general' => 'General',
                    ])
                    ->default('general')
                    ->required(),
                FileUpload::make('file_path')
                    ->label('Document')
                    ->disk('local')
                    ->directory(fn (): string => TenantStorage::path('ai-documents'))
                    ->acceptedFileTypes(['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/plain', 'text/markdown', 'text/csv', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                    ->storeFileNamesIn('original_file_name')
                    ->required()
                    ->helperText('PDF, DOC, DOCX, XLSX, CSV, or TXT. Indexed for AI search automatically after upload.'),
                Toggle::make('is_published')
                    ->default(true)
                    ->helperText('Only published, indexed documents are searchable by the Copilot.')
                    ->required(),
                Checkbox::make('privacy_declaration')
                    ->label('This document contains no candidate or employee personal data (names, contact details, pay, IDs or private notes).')
                    ->helperText('Knowledge-base content is sent to the AI provider. Contact details and IDs are also removed automatically, but names cannot be — never upload CVs, candidate lists or HR records.')
                    ->accepted()
                    ->dehydrated(false),
            ]);
    }
}
