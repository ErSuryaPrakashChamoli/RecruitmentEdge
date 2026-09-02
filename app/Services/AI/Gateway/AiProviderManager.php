<?php

namespace App\Services\AI\Gateway;

use App\Services\AI\Contracts\EmbeddingProviderInterface;
use App\Services\AI\Contracts\LLMProviderInterface;
use App\Services\AI\Contracts\WebSearchProviderInterface;
use App\Services\AI\Providers\GeminiProvider;
use App\Services\AI\Providers\NullProvider;
use App\Services\AI\Providers\OpenAiProvider;
use Illuminate\Contracts\Foundation\Application;

/**
 * The one place in the application that decides which concrete provider backs each AI capability.
 * Every other class depends only on LLMProviderInterface / EmbeddingProviderInterface /
 * WebSearchProviderInterface — never on GeminiProvider or OpenAiProvider directly.
 *
 * The three capabilities are resolved independently (spec: AI_PROVIDER / AI_EMBEDDING_PROVIDER /
 * AI_WEB_SEARCH_PROVIDER may each name a different vendor). A provider is only used for a
 * capability when it both (a) is configured (has a credential) and (b) actually implements that
 * capability's interface — a hypothetical future provider that only does chat, not embeddings,
 * falls through to NullProvider for embeddings automatically via the `instanceof` check, with no
 * separate "capability flag" system needed.
 *
 * Add a new provider by: writing the class under app/Services/AI/Providers/, adding one case to
 * resolveNamed(), and registering its singleton binding in AiServiceProvider. Nothing here or
 * above this class needs to change.
 */
class AiProviderManager
{
    public function __construct(private readonly Application $app) {}

    public function llm(): LLMProviderInterface
    {
        $provider = $this->resolveNamed(config('ai.provider'));

        return ($provider instanceof LLMProviderInterface && $provider->isConfigured())
            ? $provider
            : $this->app->make(NullProvider::class);
    }

    public function embeddings(): EmbeddingProviderInterface
    {
        $provider = $this->resolveNamed(config('ai.embeddings.provider'));

        return ($provider instanceof EmbeddingProviderInterface && $provider->isConfigured())
            ? $provider
            : $this->app->make(NullProvider::class);
    }

    public function webSearch(): WebSearchProviderInterface
    {
        $provider = $this->resolveNamed(config('ai.web_search.provider'));

        return ($provider instanceof WebSearchProviderInterface && $provider->isConfigured())
            ? $provider
            : $this->app->make(NullProvider::class);
    }

    /**
     * @return object|null null for an unrecognized provider name — callers fall back to NullProvider
     */
    private function resolveNamed(?string $name): ?object
    {
        return match ($name) {
            'gemini' => $this->app->make(GeminiProvider::class),
            'openai' => $this->app->make(OpenAiProvider::class),
            default => null,
        };
    }
}
