<?php

namespace App\Filament\Resources\AiKnowledgeArticles\Pages;

use App\Filament\Resources\AiKnowledgeArticles\AiKnowledgeArticleResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAiKnowledgeArticle extends ViewRecord
{
    protected static string $resource = AiKnowledgeArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
