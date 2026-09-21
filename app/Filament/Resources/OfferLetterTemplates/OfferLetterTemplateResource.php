<?php

namespace App\Filament\Resources\OfferLetterTemplates;

use App\Filament\Resources\OfferLetterTemplates\Pages\CreateOfferLetterTemplate;
use App\Filament\Resources\OfferLetterTemplates\Pages\EditOfferLetterTemplate;
use App\Filament\Resources\OfferLetterTemplates\Pages\ListOfferLetterTemplates;
use App\Filament\Resources\OfferLetterTemplates\Pages\ViewOfferLetterTemplate;
use App\Filament\Resources\OfferLetterTemplates\Schemas\OfferLetterTemplateForm;
use App\Filament\Resources\OfferLetterTemplates\Tables\OfferLetterTemplatesTable;
use App\Models\OfferLetterTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class OfferLetterTemplateResource extends Resource
{
    protected static ?string $model = OfferLetterTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Offer Letter Templates';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return OfferLetterTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OfferLetterTemplatesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOfferLetterTemplates::route('/'),
            'create' => CreateOfferLetterTemplate::route('/create'),
            'view' => ViewOfferLetterTemplate::route('/{record}'),
            'edit' => EditOfferLetterTemplate::route('/{record}/edit'),
        ];
    }
}
