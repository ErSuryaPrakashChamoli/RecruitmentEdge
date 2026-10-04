<?php

namespace App\Services\AI\Gateway;

use App\Enums\AiUsageRequestType;
use App\Enums\Entitlement;
use App\Models\AiUsageLog;
use App\Models\User;
use App\Services\AI\Contracts\EmbeddingProviderInterface;
use App\Services\AI\Contracts\LLMProviderInterface;
use App\Services\AI\Contracts\ReportsUsage;
use App\Services\AI\Contracts\WebSearchProviderInterface;
use App\Services\AI\DTO\LlmMessage;
use App\Services\AI\DTO\LlmResponse;
use App\Services\AI\DTO\ToolDefinition;
use App\Services\AI\DTO\WebSearchResult;
use App\Services\AI\Exceptions\AiProviderUnavailableException;
use App\Services\AI\Privacy\AiEgressGuard;
use App\Services\AI\Privacy\AiPayloadSanitizer;
use App\Services\AI\Tools\ToolExecutionContext;
use App\Services\Entitlements\EntitlementService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The only class in the application allowed to talk to an LLM/embedding/web-search provider.
 * Controllers, Filament pages/resources, and models must go through AiOrchestrator, which in turn
 * calls this Gateway — never a provider directly (spec section 9). Every call is timed and logged
 * to ai_usage_logs regardless of success/failure, so usage/cost tracking can't be bypassed by a
 * new call site forgetting to log it.
 *
 * Cost is computed per row from config('ai.pricing') (UsageCostCalculator). When a call site passes
 * no conversation id, the id of the conversation whose tool is currently executing
 * (ToolExecutionContext) is used, so model calls made inside tools are attributed correctly.
 *
 * Phase 8.1: every provider-bound payload — messages, tool-call arguments, embedding texts and
 * research queries — passes AiEgressGuard before the provider is called, whatever the caller.
 * Failure logs carry the exception class and a scrubbed message, never request or response bodies.
 *
 * SaaS-3: and before anything is sent, the tenant's plan must include the AI assistant.
 */
class AiGateway
{
    public function __construct(
        private readonly LLMProviderInterface $llm,
        private readonly EmbeddingProviderInterface $embeddings,
        private readonly WebSearchProviderInterface $webSearch,
        private readonly ModelRouter $router,
        private readonly UsageCostCalculator $costs,
        private readonly ToolExecutionContext $toolContext,
        private readonly AiEgressGuard $egress,
        private readonly AiPayloadSanitizer $sanitizer,
    ) {}

    /**
     * @param  array<int, LlmMessage>  $messages
     * @param  array<int, ToolDefinition>  $tools
     */
    public function generate(array $messages, array $tools, string $category, ?User $user = null, ?int $conversationId = null): LlmResponse
    {
        $this->assertEntitled();
        $messages = $this->egress->messages($messages, 'generate');
        $model = $this->router->forCategory($category);
        $start = microtime(true);

        try {
            $result = $this->llm->complete($messages, $tools, $model);
            $this->logUsage($user, $conversationId, AiUsageRequestType::Chat, config('ai.provider'), $model, $result->usage, $start, 'success');

            return $result;
        } catch (Throwable $e) {
            $this->logUsage($user, $conversationId, AiUsageRequestType::Chat, config('ai.provider'), $model, [], $start, 'error');
            Log::error('AiGateway::generate failed', ['exception' => $e::class, 'message' => $this->sanitizer->sanitizeText($e->getMessage())['text']]);

            throw $e;
        }
    }

    /**
     * @param  array<int, LlmMessage>  $messages
     * @param  array<int, ToolDefinition>  $tools
     */
    public function stream(array $messages, array $tools, string $category, callable $onDelta, ?User $user = null, ?int $conversationId = null): LlmResponse
    {
        $this->assertEntitled();
        $messages = $this->egress->messages($messages, 'stream');
        $model = $this->router->forCategory($category);
        $start = microtime(true);

        try {
            $result = $this->llm->stream($messages, $tools, $model, $onDelta);
            $this->logUsage($user, $conversationId, AiUsageRequestType::Chat, config('ai.provider'), $model, $result->usage, $start, 'success');

            return $result;
        } catch (Throwable $e) {
            $this->logUsage($user, $conversationId, AiUsageRequestType::Chat, config('ai.provider'), $model, [], $start, 'error');
            Log::error('AiGateway::stream failed', ['exception' => $e::class, 'message' => $this->sanitizer->sanitizeText($e->getMessage())['text']]);

            throw $e;
        }
    }

    /**
     * @param  array<int, LlmMessage>  $messages
     * @param  array<string, mixed>  $jsonSchema
     * @return array<string, mixed>
     */
    public function structured(array $messages, array $jsonSchema, string $category = 'extraction', ?User $user = null, ?int $conversationId = null): array
    {
        $this->assertEntitled();
        $messages = $this->egress->messages($messages, 'structured');
        $model = $this->router->forCategory($category);
        $start = microtime(true);

        try {
            $result = $this->llm->structured($messages, $model, $jsonSchema);
            $this->logUsage($user, $conversationId, AiUsageRequestType::Chat, config('ai.provider'), $model, $this->usageReportedBy($this->llm), $start, 'success');

            return $result;
        } catch (Throwable $e) {
            $this->logUsage($user, $conversationId, AiUsageRequestType::Chat, config('ai.provider'), $model, [], $start, 'error');
            Log::error('AiGateway::structured failed', ['exception' => $e::class, 'message' => $this->sanitizer->sanitizeText($e->getMessage())['text']]);

            throw $e;
        }
    }

    /**
     * @param  array<int, string>  $texts
     * @param  'document'|'query'  $context
     * @return array<int, array<int, float>>
     */
    public function embed(array $texts, ?User $user = null, string $context = 'document', ?int $conversationId = null): array
    {
        $this->assertEntitled();
        $texts = $this->egress->texts($texts, 'embed');
        $start = microtime(true);
        $model = $this->router->forEmbeddings();

        try {
            $vectors = $this->embeddings->embed($texts, $model, $context);
            $this->logUsage($user, $conversationId, AiUsageRequestType::Embedding, config('ai.embeddings.provider'), $model, $this->usageReportedBy($this->embeddings), $start, 'success');

            return $vectors;
        } catch (AiProviderUnavailableException $e) {
            $this->logUsage($user, $conversationId, AiUsageRequestType::Embedding, config('ai.embeddings.provider'), $model, [], $start, 'error');

            throw $e;
        }
    }

    /**
     * @return array<int, WebSearchResult>
     */
    public function research(string $query, ?User $user = null, ?int $conversationId = null): array
    {
        $this->assertEntitled();

        if (! config('ai.features.web_search_enabled')) {
            return [];
        }

        // A query the guard had to change is not sent at all: a redacted external search is
        // still a disclosure that something personal was being looked up.
        if ($this->egress->query($query, 'research') !== $query) {
            return [];
        }

        $start = microtime(true);
        $model = $this->router->forWebSearch();

        try {
            $results = $this->webSearch->search($query, ['model' => $model]);
            $this->logUsage($user, $conversationId, AiUsageRequestType::WebSearch, config('ai.web_search.provider'), $model, $this->usageReportedBy($this->webSearch), $start, 'success');

            return $results;
        } catch (AiProviderUnavailableException $e) {
            $this->logUsage($user, $conversationId, AiUsageRequestType::WebSearch, config('ai.web_search.provider'), $model, [], $start, 'error');

            return [];
        }
    }

    /**
     * SaaS-3: every provider call made for a tenant needs the AI assistant in that tenant's plan —
     * checked here, where every AI path passes, so no page, tool, job or command can go around it.
     * Platform work (no tenant: provider diagnostics) is governed by platform configuration only.
     */
    private function assertEntitled(): void
    {
        if (TenantContext::current()->hasTenant()) {
            app(EntitlementService::class)->require(Entitlement::AiAssistant);
        }
    }

    public function isConfigured(): bool
    {
        return $this->llm->isConfigured();
    }

    /**
     * @return array{input_tokens?: int, output_tokens?: int, cached_tokens?: int}
     */
    private function usageReportedBy(object $provider): array
    {
        return $provider instanceof ReportsUsage ? $provider->lastUsage() : [];
    }

    /**
     * @param  array{input_tokens?: int, output_tokens?: int, cached_tokens?: int}  $usage
     */
    private function logUsage(?User $user, ?int $conversationId, AiUsageRequestType $type, ?string $provider, string $model, array $usage, float $start, string $status): void
    {
        // SaaS-1: usage is metered per tenant (ai_usage_logs is tenant-owned). A call made with no
        // tenant (the ai:test-provider diagnostic) is platform usage: logged, never charged to a
        // tenant.
        if (! TenantContext::current()->hasTenant()) {
            Log::info('ai.platform_usage', ['provider' => (string) $provider, 'model' => $model, 'request_type' => $type->value, 'status' => $status, 'input_tokens' => $usage['input_tokens'] ?? null, 'output_tokens' => $usage['output_tokens'] ?? null]);

            return;
        }

        AiUsageLog::query()->create([
            'user_id' => $user?->id,
            'conversation_id' => $conversationId ?? $this->toolContext->conversationId(),
            'provider' => (string) $provider,
            'model' => $model,
            'request_type' => $type,
            'input_tokens' => $usage['input_tokens'] ?? null,
            'output_tokens' => $usage['output_tokens'] ?? null,
            'cached_tokens' => $usage['cached_tokens'] ?? null,
            'cost' => $this->costs->costFor($model, $usage),
            'latency_ms' => (int) ((microtime(true) - $start) * 1000),
            'status' => $status,
        ]);
    }
}
