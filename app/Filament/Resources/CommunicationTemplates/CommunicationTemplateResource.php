<?php

namespace App\Filament\Resources\CommunicationTemplates;

use App\Filament\Resources\CommunicationTemplates\Pages\CreateCommunicationTemplate;
use App\Filament\Resources\CommunicationTemplates\Pages\EditCommunicationTemplate;
use App\Filament\Resources\CommunicationTemplates\Pages\ListCommunicationTemplates;
use App\Filament\Resources\CommunicationTemplates\Pages\ViewCommunicationTemplate;
use App\Filament\Resources\CommunicationTemplates\Schemas\CommunicationTemplateForm;
use App\Filament\Resources\CommunicationTemplates\Schemas\CommunicationTemplateInfolist;
use App\Filament\Resources\CommunicationTemplates\Tables\CommunicationTemplatesTable;
use App\Models\CommunicationTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Phase 5 communication templates. Writes go through CommunicationTemplateService (variable
 * validation, versioning, snapshots); templates are archived, never deleted.
 */
class CommunicationTemplateResource extends Resource
{
    protected static ?string $model = CommunicationTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Communication';

    protected static ?string $navigationLabel = 'Templates';

    protected static ?string $modelLabel = 'communication template';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return CommunicationTemplateForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CommunicationTemplateInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CommunicationTemplatesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCommunicationTemplates::route('/'),
            'create' => CreateCommunicationTemplate::route('/create'),
            'view' => ViewCommunicationTemplate::route('/{record}'),
            'edit' => EditCommunicationTemplate::route('/{record}/edit'),
        ];
    }
}
