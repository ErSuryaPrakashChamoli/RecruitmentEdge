<?php

namespace App\Jobs\AI;

use App\Models\AiKnowledgeArticle;
use App\Services\AI\Rag\DocumentIngestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReindexKnowledgeArticleJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Phase 8.1: retried once more on failure, never runs twice at once for the same record, and
     * the uniqueness lock expires so a lost job cannot block re-indexing forever.
     */
    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(private readonly int $articleId)
    {
        // Phase 8.3: embedding calls are slow provider work — the background (intelligence)
        // worker, never the one delivering candidate messages and notifications.
        $this->onQueue(config('intelligence.queue', 'intelligence'));
    }

    public function uniqueId(): string
    {
        return (string) $this->articleId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(DocumentIngestionService $ingestion): void
    {
        $article = AiKnowledgeArticle::query()->find($this->articleId);

        if ($article !== null) {
            $ingestion->ingestKnowledgeArticle($article);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Knowledge article re-indexing failed on every attempt', ['article_id' => $this->articleId, 'exception' => $exception !== null ? $exception::class : null]);
    }
}
