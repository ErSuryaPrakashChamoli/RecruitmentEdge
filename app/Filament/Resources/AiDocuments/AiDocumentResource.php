<?php

namespace App\Filament\Resources\AiDocuments;

use App\Filament\Resources\AiDocuments\Pages\CreateAiDocument;
use App\Filament\Resources\AiDocuments\Pages\ListAiDocuments;
use App\Filament\Resources\AiDocuments\Schemas\AiDocumentForm;
use App\Filament\Resources\AiDocuments\Tables\AiDocumentsTable;
use App\Models\AiDocument;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AiDocumentResource extends Resource
{
    protected static ?string $model = AiDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'AI Assistant';

    protected static ?string $navigationLabel = 'Knowledge Documents';

    public static function form(Schema $schema): Schema
    {
        return AiDocumentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AiDocumentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiDocuments::route('/'),
            'create' => CreateAiDocument::route('/create'),
        ];
    }
}
