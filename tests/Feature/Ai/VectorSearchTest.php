<?php

use App\Models\AiKnowledgeArticle;
use App\Services\AI\Contracts\EmbeddingProviderInterface;
use App\Services\AI\Rag\DocumentIngestionService;
use App\Services\AI\Rag\VectorSearch;

/**
 * A deterministic bag-of-words fake so ranking behavior can be asserted without a real embedding
 * API — presence/absence of a fixed vocabulary determines the vector, giving exact, predictable
 * cosine similarities.
 */
class FakeBagOfWordsEmbeddingProvider implements EmbeddingProviderInterface
{
    private const array VOCAB = ['notice', 'period', 'referral', 'bonus'];

    public function isConfigured(): bool
    {
        return true;
    }

    public function embed(array $texts, ?string $model = null, string $context = 'document'): array
    {
        return array_map(function (string $text) {
            $lower = strtolower($text);

            return array_map(fn (string $word) => str_contains($lower, $word) ? 1.0 : 0.0, self::VOCAB);
        }, $texts);
    }
}

test('cosine similarity is 1.0 for identical vectors and 0.0 for orthogonal vectors', function (): void {
    expect(round(VectorSearch::cosine([1.0, 1.0, 0.0], [1.0, 1.0, 0.0]), 6))->toBe(1.0)
        ->and(VectorSearch::cosine([1.0, 0.0], [0.0, 1.0]))->toBe(0.0)
        ->and(VectorSearch::cosine([], []))->toBe(0.0);
});

test('semantic search ranks the topically relevant knowledge article above an unrelated one', function (): void {
    $this->app->bind(EmbeddingProviderInterface::class, fn () => new FakeBagOfWordsEmbeddingProvider);

    $noticeArticle = AiKnowledgeArticle::factory()->create([
        'title' => 'Notice Period Policy',
        'content' => 'Employees must serve a notice period before their last working day. The standard notice period is 30 days.',
        'is_published' => true,
    ]);

    $referralArticle = AiKnowledgeArticle::factory()->create([
        'title' => 'Referral Program',
        'content' => 'Employees who refer a successful candidate receive a referral bonus after 90 days of the new hire\'s employment.',
        'is_published' => true,
    ]);

    $ingestion = app(DocumentIngestionService::class);
    $ingestion->ingestKnowledgeArticle($noticeArticle);
    $ingestion->ingestKnowledgeArticle($referralArticle);

    $results = app(VectorSearch::class)->search('notice period');

    expect($results)->toHaveCount(1)
        ->and($results->first()['source_id'])->toBe($noticeArticle->id)
        ->and(round($results->first()['score'], 6))->toBe(1.0);
});

test('vector search returns nothing when RAG is disabled', function (): void {
    config(['ai.features.rag_enabled' => false]);
    $this->app->bind(EmbeddingProviderInterface::class, fn () => new FakeBagOfWordsEmbeddingProvider);

    expect(app(VectorSearch::class)->search('notice period'))->toBeEmpty();
});
