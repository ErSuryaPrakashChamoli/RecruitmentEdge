<?php

namespace App\Jobs\AI;

use App\Enums\AiDocumentStatus;
use App\Enums\Entitlement;
use App\Models\AiDocument;
use App\Services\AI\Rag\DocumentIngestionService;
use App\Services\Entitlements\SkipWithoutEntitlement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class IndexAiDocumentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Phase 8.1: retried once more on failure, never runs twice at once for the same record, and
     * the uniqueness lock expires so a lost job cannot block re-indexing forever.
     */
    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(private readonly int $documentId)
    {
        // Phase 8.3: embedding calls are slow provider work — the background (intelligence)
        // worker, never the one delivering candidate messages and notifications.
        $this->onQueue(config('intelligence.queue', 'intelligence'));
    }

    public function uniqueId(): string
    {
        return (string) $this->documentId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * SaaS-3: AI work runs only while the tenant's plan includes the AI assistant.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new SkipWithoutEntitlement(Entitlement::AiAssistant)];
    }

    public function handle(DocumentIngestionService $ingestion): void
    {
        $document = AiDocument::query()->find($this->documentId);

        if ($document !== null) {
            $ingestion->ingestDocument($document);
        }
    }

    public function failed(?Throwable $exception): void
    {
        AiDocument::query()->whereKey($this->documentId)->update(['status' => AiDocumentStatus::Failed, 'error' => 'Indexing failed on every attempt — try re-indexing.']);
    }
}
