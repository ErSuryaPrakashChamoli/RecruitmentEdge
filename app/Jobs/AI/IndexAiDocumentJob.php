<?php

namespace App\Jobs\AI;

use App\Models\AiDocument;
use App\Services\AI\Rag\DocumentIngestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class IndexAiDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $documentId) {}

    public function handle(DocumentIngestionService $ingestion): void
    {
        $document = AiDocument::query()->find($this->documentId);

        if ($document !== null) {
            $ingestion->ingestDocument($document);
        }
    }
}
