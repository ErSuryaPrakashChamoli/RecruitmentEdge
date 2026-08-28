<?php

namespace App\Filament\Resources\AiKnowledgeArticles\Pages;

use App\Filament\Resources\AiKnowledgeArticles\AiKnowledgeArticleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAiKnowledgeArticles extends ListRecords
{
    protected static string $resource = AiKnowledgeArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
