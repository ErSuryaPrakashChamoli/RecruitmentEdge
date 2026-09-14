<?php

namespace App\Services\AI\Orchestrator;

use App\Models\AiKnowledgeArticle;
use App\Models\User;
use App\Services\AiAssistantService;
use Illuminate\Support\Str;

/**
 * The Copilot's answer when no LLM provider is configured: a deterministic keyword search over
 * published knowledge articles via AiAssistantService (which also logs to ai_query_logs), listing
 * the matches with a clear note that full AI is off. Keeps the "AI still works with no API key"
 * guarantee instead of every question ending in a bare "not configured" message.
 */
class KnowledgeBaseFallback
{
    public function __construct(private readonly AiAssistantService $assistant) {}

    public function answer(string $question, User $user): string
    {
        $articles = $this->assistant->ask($question, $user->id)['articles'];

        $note = '_Full AI is not configured, so this answer comes from a keyword search of the published '
            .'knowledge base. Ask an administrator to set AI_PROVIDER and its API key (GEMINI_API_KEY for '
            .'the default Gemini provider, or OPENAI_API_KEY) to enable the full Copilot._';

        if ($articles->isEmpty()) {
            return "I couldn't find any published knowledge base articles matching your question. "
                ."Try different keywords, or ask HR to add an article covering it.\n\n{$note}";
        }

        $list = $articles->values()->map(function (AiKnowledgeArticle $article, int $index): string {
            $excerpt = Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags((string) $article->content))), 280);
            $category = filled($article->category) ? ' ('.Str::headline($article->category).')' : '';

            return ($index + 1).". **{$article->title}**{$category}\n   {$excerpt}";
        })->implode("\n\n");

        return "Here are the knowledge base articles that best match your question:\n\n{$list}\n\n{$note}";
    }
}
