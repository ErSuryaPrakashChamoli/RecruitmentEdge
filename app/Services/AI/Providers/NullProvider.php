<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\EmbeddingProviderInterface;
use App\Services\AI\Contracts\LLMProviderInterface;
use App\Services\AI\Contracts\WebSearchProviderInterface;
use App\Services\AI\DTO\LlmMessage;
use App\Services\AI\DTO\LlmResponse;
use App\Services\AI\DTO\ToolDefinition;
use App\Services\AI\DTO\WebSearchResult;
use App\Services\AI\Exceptions\AiProviderUnavailableException;

/**
 * Bound instead of a real provider whenever no credential is configured (see AiServiceProvider).
 * Keeps the whole application working with AI switched off (spec section 59/64): chat degrades to
 * a clear message rather than an error, while embeddings/search — which have no safe fallback
 * value — throw so callers can fail their specific operation gracefully.
 */
class NullProvider implements EmbeddingProviderInterface, LLMProviderInterface, WebSearchProviderInterface
{
    public function isConfigured(): bool
    {
        return false;
    }

    /**
     * @param  array<int, LlmMessage>  $messages
     * @param  array<int, ToolDefinition>  $tools
     * @param  array<string, mixed>  $options
     */
    public function complete(array $messages, array $tools, string $model, array $options = []): LlmResponse
    {
        return LlmResponse::unconfigured($this->message(), $model);
    }

    /**
     * @param  array<int, LlmMessage>  $messages
     * @param  array<int, ToolDefinition>  $tools
     * @param  array<string, mixed>  $options
     */
    public function stream(array $messages, array $tools, string $model, callable $onDelta, array $options = []): LlmResponse
    {
        $message = $this->message();
        $onDelta($message);

        return LlmResponse::unconfigured($message, $model);
    }

    /**
     * @param  array<int, LlmMessage>  $messages
     * @param  array<string, mixed>  $jsonSchema
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function structured(array $messages, string $model, array $jsonSchema, array $options = []): array
    {
        return [];
    }

    /**
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public function embed(array $texts, ?string $model = null, string $context = 'document'): array
    {
        throw new AiProviderUnavailableException('No embedding provider is configured (set GEMINI_API_KEY or OPENAI_API_KEY).');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<int, WebSearchResult>
     */
    public function search(string $query, array $options = []): array
    {
        throw new AiProviderUnavailableException('No web search provider is configured (set OPENAI_API_KEY).');
    }

    private function message(): string
    {
        return 'AI is not configured yet. Ask an administrator to add an OPENAI_API_KEY so I can '
            .'answer with real reasoning — the rest of the app works normally in the meantime.';
    }
}
