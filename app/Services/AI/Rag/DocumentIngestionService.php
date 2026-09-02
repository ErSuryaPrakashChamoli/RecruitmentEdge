<?php

namespace App\Services\AI\Rag;

use App\Enums\AiDocumentStatus;
use App\Models\AiDocument;
use App\Models\AiDocumentChunk;
use App\Models\AiKnowledgeArticle;
use App\Services\AI\Contracts\DocumentParserInterface;
use App\Services\AI\Exceptions\AiProviderUnavailableException;
use App\Services\AI\Gateway\AiGateway;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pipeline: extract text (per-mime DocumentParserInterface) -> normalize -> chunk -> embed -> store
 * (spec section 21). Runs inside IndexAiDocumentJob/ReindexKnowledgeArticleJob on the queue so
 * uploads/publishes never block the request.
 */
class DocumentIngestionService
{
    /**
     * @param  iterable<DocumentParserInterface>  $parsers
     */
    public function __construct(
        private readonly AiGateway $gateway,
        private readonly iterable $parsers,
    ) {}

    public function ingestDocument(AiDocument $document): void
    {
        $document->forceFill(['status' => AiDocumentStatus::Processing, 'error' => null])->save();

        try {
            $absolutePath = Storage::disk($document->disk)->path($document->file_path);
            $extension = Str::lower(pathinfo($document->file_path, PATHINFO_EXTENSION));
            $parser = $this->parserFor($document->mime_type ?? '', $extension);

            if ($parser === null) {
                throw new \RuntimeException("No parser available for file type [{$extension}].");
            }

            $text = trim($parser->extractText($absolutePath));

            if ($text === '') {
                throw new \RuntimeException('No extractable text was found in this document.');
            }

            $this->replaceChunks('document', $document->id, $text);

            $document->forceFill(['status' => AiDocumentStatus::Indexed])->save();
        } catch (Throwable $e) {
            $document->forceFill(['status' => AiDocumentStatus::Failed, 'error' => $e->getMessage()])->save();
        }
    }

    public function ingestKnowledgeArticle(AiKnowledgeArticle $article): void
    {
        try {
            $text = trim(strip_tags($article->title."\n\n".$article->content));

            if ($text !== '') {
                $this->replaceChunks('knowledge_article', $article->id, $text);
            }
        } catch (AiProviderUnavailableException) {
            // RAG stays disabled until a provider is configured; the article is still searchable
            // via the keyword fallback in AiAssistantService/SearchKnowledgeBaseTool.
        }
    }

    private function replaceChunks(string $sourceType, int $sourceId, string $text): void
    {
        $chunks = $this->chunk($text);
        $vectors = $this->gateway->embed($chunks);

        AiDocumentChunk::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->delete();

        foreach ($chunks as $index => $content) {
            AiDocumentChunk::query()->create([
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'chunk_index' => $index,
                'content' => $content,
                'embedding' => $vectors[$index] ?? [],
                'token_count' => str_word_count($content),
            ]);
        }
    }

    /**
     * Word-count-based chunking (a rough proxy for tokens — English averages ~0.75 words/token,
     * close enough for chunk sizing without pulling in a full tokenizer dependency).
     *
     * @return array<int, string>
     */
    private function chunk(string $text): array
    {
        $words = preg_split('/\s+/', $text, flags: PREG_SPLIT_NO_EMPTY) ?: [];
        $chunkSize = (int) config('ai.rag.chunk_size_tokens');
        $overlap = (int) config('ai.rag.chunk_overlap_tokens');
        $step = max(1, $chunkSize - $overlap);

        $chunks = [];

        for ($start = 0; $start < count($words); $start += $step) {
            $chunks[] = implode(' ', array_slice($words, $start, $chunkSize));

            if ($start + $chunkSize >= count($words)) {
                break;
            }
        }

        return $chunks === [] ? [$text] : $chunks;
    }

    private function parserFor(string $mimeType, string $extension): ?DocumentParserInterface
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($mimeType, $extension)) {
                return $parser;
            }
        }

        return null;
    }
}
