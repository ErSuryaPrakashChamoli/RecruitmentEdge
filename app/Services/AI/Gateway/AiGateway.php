<?php

namespace App\Services\AI\Gateway;

use App\Enums\AiUsageRequestType;
use App\Models\AiUsageLog;
use App\Models\User;
use App\Services\AI\Contracts\EmbeddingProviderInterface;
use App\Services\AI\Contracts\LLMProviderInterface;
use App\Services\AI\Contracts\WebSearchProviderInterface;
use App\Services\AI\DTO\LlmMessage;
use App\Services\AI\DTO\LlmResponse;
use App\Services\AI\DTO\ToolDefinition;
use App\Services\AI\DTO\WebSearchResult;
use App\Services\AI\Exceptions\AiProviderUnavailableException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The only class in the application allowed to talk to an LLM/embedding/web-search provider.
 * Controllers, Filament pages/resources, and models must go through AiOrchestrator, which in turn
 * calls this Gateway — never a provider directly (spec section 9). Every call is timed and logged
 * to ai_usage_logs regardless of success/failure, so usage/cost tracking can't be bypassed by a
 * new call site forgetting to log it.
 */
class AiGateway
{
    public function __construct(
        private readonly LLMProviderInterface $llm,
        private readonly EmbeddingProviderInterface $embeddings,
        private readonly WebSearchProviderInterface $webSearch,
        private readonly ModelRouter $router,
    ) {}

    /**
     * @param  array<int, LlmMessage>  $messages
     * @param  array<int, ToolDefinition>  $tools
     */
    public function generate(array $messages, array $tools, string $category, ?User $user = null, ?int $conversationId = null): LlmResponse
    {
        $model = $this->router->forCategory($category);
        $start = microtime(true);

        try {
            $result = $this->llm->complete($messages, $tools, $model);
            $this->logUsage($user, $conversationId, AiUsageRequestType::Chat, $model, $result->usage, $start, 'success');

            return $result;
        } catch (Throwable $e) {
            $this->logUsage($user, $conversationId, AiUsageRequestType::Chat, $model, [], $start, 'error');
            Log::error('AiGateway::generate failed', ['exception' => $e->getMessage()]);

            throw $e;
        }
    }

    /**
     * @param  array<int, LlmMessage>  $messages
     * @param  array<int, ToolDefinition>  $tools
     */
    public function stream(array $messages, array $tools, string $category, callable $onDelta, ?User $user = null, ?int $conversationId = null): LlmResponse
    {
        $model = $this->router->forCategory($category);
        $start = microtime(true);

        try {
            $result = $this->llm->stream($messages, $tools, $model, $onDelta);
            $this->logUsage($user, $conversationId, AiUsageRequestType::Chat, $model, $result->usage, $start, 'success');

            return $result;
        } catch (Throwable $e) {
            $this->logUsage($user, $conversationId, AiUsageRequestType::Chat, $model, [], $start, 'error');
            Log::error('AiGateway::stream failed', ['exception' => $e->getMessage()]);

            throw $e;
        }
    }

    /**
     * @param  array<int, LlmMessage>  $messages
     * @param  array<string, mixed>  $jsonSchema
     * @return array<string, mixed>
     */
    public function structured(array $messages, array $jsonSchema, string $category = 'extraction'): array
    {
        $model = $this->router->forCategory($category);

        return $this->llm->structured($messages, $model, $jsonSchema);
    }

    /**
     * @param  array<int, string>  $texts
     * @param  'document'|'query'  $context
     * @return array<int, array<int, float>>
     */
    public function embed(array $texts, ?User $user = null, string $context = 'document'): array
    {
        $start = microtime(true);
        $model = config('ai.embeddings.model');

        try {
            $vectors = $this->embeddings->embed($texts, $model, $context);
            $this->logUsage($user, null, AiUsageRequestType::Embedding, $model, [], $start, 'success');

            return $vectors;
        } catch (AiProviderUnavailableException $e) {
            $this->logUsage($user, null, AiUsageRequestType::Embedding, $model, [], $start, 'error');

            throw $e;
        }
    }

    /**
     * @return array<int, WebSearchResult>
     */
    public function research(string $query, ?User $user = null): array
    {
        if (! config('ai.features.web_search_enabled')) {
            return [];
        }

        $start = microtime(true);
        $model = $this->router->forCategory('balanced');

        try {
            $results = $this->webSearch->search($query, ['model' => $model]);
            $this->logUsage($user, null, AiUsageRequestType::WebSearch, $model, [], $start, 'success');

            return $results;
        } catch (AiProviderUnavailableException $e) {
            $this->logUsage($user, null, AiUsageRequestType::WebSearch, $model, [], $start, 'error');

            return [];
        }
    }

    public function isConfigured(): bool
    {
        return $this->llm->isConfigured();
    }

    /**
     * @param  array{input_tokens?: int, output_tokens?: int, cached_tokens?: int}  $usage
     */
    private function logUsage(?User $user, ?int $conversationId, AiUsageRequestType $type, string $model, array $usage, float $start, string $status): void
    {
        AiUsageLog::query()->create([
            'user_id' => $user?->id,
            'conversation_id' => $conversationId,
            'provider' => config('ai.provider'),
            'model' => $model,
            'request_type' => $type,
            'input_tokens' => $usage['input_tokens'] ?? null,
            'output_tokens' => $usage['output_tokens'] ?? null,
            'cached_tokens' => $usage['cached_tokens'] ?? null,
            'latency_ms' => (int) ((microtime(true) - $start) * 1000),
            'status' => $status,
        ]);
    }
}
