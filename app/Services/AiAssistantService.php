<?php

namespace App\Services;

use App\Models\AiKnowledgeArticle;
use App\Models\AiQueryLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A searchable FAQ/knowledge-base assistant — deliberately NOT a live LLM integration. No AI
 * provider API key is configured in this environment, so answers come from keyword matching
 * against admin-curated AiKnowledgeArticle content rather than a generated response.
 *
 * To upgrade this to a real LLM later: keep ask()'s signature and logging behavior, but replace
 * the keyword search below with a call to your provider (e.g. via Laravel's Http client), still
 * grounding the prompt in the same scoped article search so answers stay auditable and don't leak
 * data outside the asker's hierarchy.
 */
class AiAssistantService
{
    /**
     * @return array{answer: string, articles: Collection<int, AiKnowledgeArticle>}
     */
    public function ask(string $question, ?int $userId = null): array
    {
        $articles = $this->search($question);

        $answer = $articles->isNotEmpty()
            ? $articles->first()->content
            : "I couldn't find anything in the knowledge base for that yet. Try rephrasing, or ask HR to add an article covering it.";

        AiQueryLog::query()->create([
            'user_id' => $userId,
            'question' => $question,
            'matched_article_ids' => $articles->pluck('id')->all(),
            'answer' => $answer,
        ]);

        return ['answer' => $answer, 'articles' => $articles];
    }

    /**
     * @return Collection<int, AiKnowledgeArticle>
     */
    public function search(string $question, int $limit = 5): Collection
    {
        $words = collect(preg_split('/\s+/', Str::lower(trim($question))))
            ->filter(fn (string $word) => mb_strlen($word) >= 3)
            ->unique();

        if ($words->isEmpty()) {
            return collect();
        }

        return AiKnowledgeArticle::query()
            ->where('is_published', true)
            ->where(function ($query) use ($words): void {
                $words->each(function (string $word) use ($query): void {
                    $query->orWhere('title', 'like', "%{$word}%")
                        ->orWhere('content', 'like', "%{$word}%");
                });
            })
            ->limit($limit)
            ->get();
    }
}
