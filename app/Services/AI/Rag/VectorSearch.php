<?php

namespace App\Services\AI\Rag;

use App\Enums\AiDocumentStatus;
use App\Models\AiDocument;
use App\Models\AiDocumentChunk;
use App\Models\AiKnowledgeArticle;
use App\Services\AI\Exceptions\AiProviderUnavailableException;
use App\Services\AI\Gateway\AiGateway;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * Brute-force cosine similarity search over ai_document_chunks — a deliberate choice (not
 * pgvector/etc.) to match the app's current database engine and knowledge-base scale; revisit if
 * the corpus grows large enough for a full scan per query to matter. Only chunks belonging to
 * published documents/articles are ever considered.
 *
 * SaaS-1: each tenant has its own knowledge base. Chunks carry tenant_id themselves and are filtered
 * on it directly — never only through their parent document — so retrieval can only return the
 * current tenant's text (and the scan stays within one tenant's corpus).
 */
class VectorSearch
{
    public function __construct(private readonly AiGateway $gateway) {}

    /**
     * @return Collection<int, array{source_type: string, source_id: int, content: string, score: float}>
     */
    public function search(string $query, ?int $topK = null, ?string $sourceType = null): Collection
    {
        if (! config('ai.features.rag_enabled')) {
            return collect();
        }

        try {
            $queryVector = $this->gateway->embed([$query], context: 'query')[0] ?? null;
        } catch (AiProviderUnavailableException) {
            return collect();
        }

        if ($queryVector === null) {
            return collect();
        }

        $topK ??= (int) config('ai.rag.top_k');
        $minSimilarity = (float) config('ai.rag.min_similarity');

        return $this->searchableChunks($sourceType)
            ->map(function (AiDocumentChunk $chunk) use ($queryVector) {
                return [
                    'source_type' => $chunk->source_type,
                    'source_id' => $chunk->source_id,
                    'content' => $chunk->content,
                    'score' => self::cosine($queryVector, $chunk->embedding ?? []),
                ];
            })
            ->filter(fn (array $row) => $row['score'] >= $minSimilarity)
            ->sortByDesc('score')
            ->take($topK)
            ->values();
    }

    /**
     * @return Collection<int, AiDocumentChunk>
     */
    private function searchableChunks(?string $sourceType): Collection
    {
        $publishedDocumentIds = AiDocument::query()
            ->where('is_published', true)
            ->where('status', AiDocumentStatus::Indexed)
            ->whereNotNull('privacy_declared_at')
            ->pluck('id');

        $publishedArticleIds = AiKnowledgeArticle::query()
            ->where('is_published', true)
            ->pluck('id');

        return AiDocumentChunk::query()
            ->where('ai_document_chunks.tenant_id', TenantContext::current()->requireId())
            ->when($sourceType !== null, fn ($q) => $q->where('source_type', $sourceType))
            ->where(function ($query) use ($publishedDocumentIds, $publishedArticleIds): void {
                $query->where(fn ($q) => $q->where('source_type', 'document')->whereIn('source_id', $publishedDocumentIds))
                    ->orWhere(fn ($q) => $q->where('source_type', 'knowledge_article')->whereIn('source_id', $publishedArticleIds));
            })
            ->get();
    }

    /**
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    public static function cosine(array $a, array $b): float
    {
        if ($a === [] || count($a) !== count($b)) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $i => $valueA) {
            $valueB = $b[$i] ?? 0.0;
            $dot += $valueA * $valueB;
            $normA += $valueA ** 2;
            $normB += $valueB ** 2;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
