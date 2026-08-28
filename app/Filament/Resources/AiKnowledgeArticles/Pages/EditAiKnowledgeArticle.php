<?php

namespace App\Filament\Resources\AiKnowledgeArticles\Pages;

use App\Filament\Resources\AiKnowledgeArticles\AiKnowledgeArticleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAiKnowledgeArticle extends EditRecord
{
    protected static string $resource = AiKnowledgeArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
