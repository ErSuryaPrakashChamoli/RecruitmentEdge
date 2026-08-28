<?php

namespace App\Filament\Resources\AiKnowledgeArticles;

use App\Filament\Resources\AiKnowledgeArticles\Pages\CreateAiKnowledgeArticle;
use App\Filament\Resources\AiKnowledgeArticles\Pages\EditAiKnowledgeArticle;
use App\Filament\Resources\AiKnowledgeArticles\Pages\ListAiKnowledgeArticles;
use App\Filament\Resources\AiKnowledgeArticles\Schemas\AiKnowledgeArticleForm;
use App\Filament\Resources\AiKnowledgeArticles\Tables\AiKnowledgeArticlesTable;
use App\Models\AiKnowledgeArticle;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AiKnowledgeArticleResource extends Resource
{
    protected static ?string $model = AiKnowledgeArticle::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'AI Assistant';

    protected static ?string $navigationLabel = 'Knowledge Base';

    public static function form(Schema $schema): Schema
    {
        return AiKnowledgeArticleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AiKnowledgeArticlesTable::configure($table);
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
            'index' => ListAiKnowledgeArticles::route('/'),
            'create' => CreateAiKnowledgeArticle::route('/create'),
            'edit' => EditAiKnowledgeArticle::route('/{record}/edit'),
        ];
    }
}
