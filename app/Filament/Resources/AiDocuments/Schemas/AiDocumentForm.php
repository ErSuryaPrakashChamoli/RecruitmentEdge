<?php

namespace App\Filament\Resources\AiDocuments\Schemas;

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
                    ->directory('ai-documents')
                    ->acceptedFileTypes(['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/plain', 'text/markdown', 'text/csv', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                    ->storeFileNamesIn('original_file_name')
                    ->required()
                    ->helperText('PDF, DOCX, XLSX, CSV, or TXT. Indexed for AI search automatically after upload.'),
                Toggle::make('is_published')
                    ->default(true)
                    ->helperText('Only published, indexed documents are searchable by the Copilot.')
                    ->required(),
            ]);
    }
}
