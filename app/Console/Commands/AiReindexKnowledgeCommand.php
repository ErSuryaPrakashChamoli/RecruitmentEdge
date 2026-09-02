<?php

namespace App\Console\Commands;

use App\Jobs\AI\IndexAiDocumentJob;
use App\Jobs\AI\ReindexKnowledgeArticleJob;
use App\Models\AiDocument;
use App\Models\AiKnowledgeArticle;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ai:reindex-knowledge')]
#[Description('Queue re-embedding of every published knowledge article and document for RAG search')]
class AiReindexKnowledgeCommand extends Command
{
    public function handle(): int
    {
        $articleIds = AiKnowledgeArticle::query()->where('is_published', true)->pluck('id');
        $articleIds->each(fn (int $id) => ReindexKnowledgeArticleJob::dispatch($id));

        $documentIds = AiDocument::query()->where('is_published', true)->pluck('id');
        $documentIds->each(fn (int $id) => IndexAiDocumentJob::dispatch($id));

        $this->info("Queued reindexing for {$articleIds->count()} article(s) and {$documentIds->count()} document(s).");

        return self::SUCCESS;
    }
}
