<?php

namespace App\Jobs\AI;

use App\Models\AiKnowledgeArticle;
use App\Services\AI\Rag\DocumentIngestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReindexKnowledgeArticleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $articleId) {}

    public function handle(DocumentIngestionService $ingestion): void
    {
        $article = AiKnowledgeArticle::query()->find($this->articleId);

        if ($article !== null) {
            $ingestion->ingestKnowledgeArticle($article);
        }
    }
}
