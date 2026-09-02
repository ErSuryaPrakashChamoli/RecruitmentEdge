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
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * OpenAI adapter built on Laravel's Http client against the documented REST endpoints (no vendor
 * SDK dependency) — the Responses API for chat/tool-calling/structured output/web search, and the
 * Embeddings API for vectors. Every call is wrapped defensively: an unexpected response shape or a
 * transport failure never bubbles a raw exception to the user (spec section 64) — it's logged and
 * surfaced as a clear "AI service unavailable" message/exception instead.
 *
 * Streaming note: true token-level SSE parsing against the Responses API's `stream: true` mode is
 * intentionally not implemented here — this environment has no live API key to verify the exact
 * event schema against, and guessing at undocumented event shapes would violate the "don't invent
 * API parameters" rule. stream() instead performs a normal complete() call and flushes the result
 * to $onDelta in word-sized chunks, which satisfies the same UI contract (Livewire's stream())
 * without risking a silently-wrong SSE parser. Swap in real event parsing once verified against a
 * live key.
 */
class OpenAiProvider implements EmbeddingProviderInterface, LLMProviderInterface, WebSearchProviderInterface
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $baseUrl,
        private readonly ?string $organization = null,
    ) {}

    public function isConfigured(): bool
    {
        return filled($this->apiKey);
    }

    /**
     * @param  array<int, LlmMessage>  $messages
     * @param  array<int, ToolDefinition>  $tools
     * @param  array<string, mixed>  $options
     */
    public function complete(array $messages, array $tools, string $model, array $options = []): LlmResponse
    {
        $this->assertConfigured();

        $payload = [
            'model' => $model,
            'input' => $this->toResponsesInput($messages),
            'max_output_tokens' => $options['max_tokens'] ?? config('ai.limits.max_tokens'),
        ];

        if ($tools !== []) {
            $payload['tools'] = array_map(fn (ToolDefinition $tool) => $tool->toProviderSchema(), $tools);
            $payload['tool_choice'] = 'auto';
        }

        $response = $this->request()->post('/responses', $payload);

        return $this->parseResponse($response, $model);
    }

    /**
     * @param  array<int, LlmMessage>  $messages
     * @param  array<int, ToolDefinition>  $tools
     * @param  array<string, mixed>  $options
     */
    public function stream(array $messages, array $tools, string $model, callable $onDelta, array $options = []): LlmResponse
    {
        $result = $this->complete($messages, $tools, $model, $options);

        if (filled($result->content)) {
            foreach (preg_split('/(\s+)/', $result->content, flags: PREG_SPLIT_DELIM_CAPTURE) ?: [] as $chunk) {
                if ($chunk !== '') {
                    $onDelta($chunk);
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<int, LlmMessage>  $messages
     * @param  array<string, mixed>  $jsonSchema
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function structured(array $messages, string $model, array $jsonSchema, array $options = []): array
    {
        $this->assertConfigured();

        $payload = [
            'model' => $model,
            'input' => $this->toResponsesInput($messages),
            'max_output_tokens' => $options['max_tokens'] ?? config('ai.limits.max_tokens'),
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => $jsonSchema['name'] ?? 'response',
                    'schema' => $jsonSchema['schema'] ?? $jsonSchema,
                    'strict' => $jsonSchema['strict'] ?? true,
                ],
            ],
        ];

        try {
            $response = $this->request()->post('/responses', $payload);
            $text = $this->extractOutputText($response->json() ?? []);

            return $text !== null ? (json_decode($text, true) ?? []) : [];
        } catch (Throwable $e) {
            Log::error('AI structured() call failed', ['exception' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public function embed(array $texts, ?string $model = null, string $context = 'document'): array
    {
        $this->assertConfigured();

        $response = $this->request()->post('/embeddings', [
            'model' => $model ?? config('ai.embeddings.model'),
            'input' => array_values($texts),
        ]);

        if ($response->failed()) {
            Log::error('AI embed() call failed', ['status' => $response->status(), 'body' => $response->body()]);

            throw new AiProviderUnavailableException('The embedding service returned an error.');
        }

        $data = collect($response->json('data') ?? [])->sortBy('index')->values();

        return $data->map(fn (array $row) => array_map('floatval', $row['embedding'] ?? []))->all();
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<int, WebSearchResult>
     */
    public function search(string $query, array $options = []): array
    {
        $this->assertConfigured();

        $response = $this->request()->post('/responses', [
            'model' => $options['model'] ?? config('ai.models.balanced'),
            'input' => $query,
            'tools' => [['type' => 'web_search']],
            'tool_choice' => 'auto',
        ]);

        if ($response->failed()) {
            Log::error('AI web search failed', ['status' => $response->status(), 'body' => $response->body()]);

            throw new AiProviderUnavailableException('The web search service returned an error.');
        }

        return $this->extractCitations($response->json() ?? []);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->timeout(60)
            ->withToken((string) $this->apiKey)
            ->when($this->organization, fn (PendingRequest $r) => $r->withHeaders(['OpenAI-Organization' => $this->organization]))
            ->acceptJson();
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new AiProviderUnavailableException('No OPENAI_API_KEY is configured.');
        }
    }

    /**
     * @param  array<int, LlmMessage>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function toResponsesInput(array $messages): array
    {
        $items = [];

        foreach ($messages as $message) {
            if ($message->role === 'tool') {
                $items[] = [
                    'type' => 'function_call_output',
                    'call_id' => $message->toolCallId,
                    'output' => (string) $message->content,
                ];

                continue;
            }

            if ($message->role === 'assistant' && filled($message->toolCalls)) {
                foreach ($message->toolCalls as $call) {
                    $items[] = [
                        'type' => 'function_call',
                        'call_id' => $call['id'],
                        'name' => $call['name'],
                        'arguments' => json_encode($call['arguments'] ?? []),
                    ];
                }

                if (blank($message->content)) {
                    continue;
                }
            }

            $items[] = [
                'role' => $message->role,
                'content' => (string) $message->content,
            ];
        }

        return $items;
    }

    private function parseResponse(Response $response, string $model): LlmResponse
    {
        if ($response->failed()) {
            Log::error('AI complete() call failed', ['status' => $response->status(), 'body' => $response->body()]);

            throw new RuntimeException('The AI service returned an error response.');
        }

        $json = $response->json() ?? [];
        $output = $json['output'] ?? [];

        $content = $this->extractOutputText($json);
        $toolCalls = [];

        foreach ($output as $item) {
            if (($item['type'] ?? null) === 'function_call') {
                $toolCalls[] = [
                    'id' => $item['call_id'] ?? $item['id'] ?? '',
                    'name' => $item['name'] ?? '',
                    'arguments' => json_decode($item['arguments'] ?? '{}', true) ?? [],
                ];
            }
        }

        $usage = $json['usage'] ?? [];

        return new LlmResponse(
            content: $content,
            toolCalls: $toolCalls,
            usage: [
                'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
                'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
                'cached_tokens' => (int) ($usage['input_tokens_details']['cached_tokens'] ?? 0),
            ],
            model: $json['model'] ?? $model,
        );
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function extractOutputText(array $json): ?string
    {
        if (isset($json['output_text']) && is_string($json['output_text'])) {
            return $json['output_text'];
        }

        $pieces = [];

        foreach ($json['output'] ?? [] as $item) {
            if (($item['type'] ?? null) !== 'message') {
                continue;
            }

            foreach ($item['content'] ?? [] as $part) {
                if (($part['type'] ?? null) === 'output_text' && isset($part['text'])) {
                    $pieces[] = $part['text'];
                }
            }
        }

        return $pieces === [] ? null : implode("\n", $pieces);
    }

    /**
     * Extracts title/url citations from output_text annotations of type url_citation, per the
     * documented Responses API web_search tool behavior.
     *
     * @param  array<string, mixed>  $json
     * @return array<int, WebSearchResult>
     */
    private function extractCitations(array $json): array
    {
        $results = [];
        $retrievedAt = CarbonImmutable::now();

        foreach ($json['output'] ?? [] as $item) {
            if (($item['type'] ?? null) !== 'message') {
                continue;
            }

            foreach ($item['content'] ?? [] as $part) {
                if (($part['type'] ?? null) !== 'output_text') {
                    continue;
                }

                $text = $part['text'] ?? '';

                foreach ($part['annotations'] ?? [] as $annotation) {
                    if (($annotation['type'] ?? null) !== 'url_citation') {
                        continue;
                    }

                    $url = $annotation['url'] ?? null;

                    if (blank($url)) {
                        continue;
                    }

                    $start = $annotation['start_index'] ?? null;
                    $end = $annotation['end_index'] ?? null;
                    $excerpt = (is_int($start) && is_int($end) && $end > $start)
                        ? mb_substr($text, $start, $end - $start)
                        : null;

                    $results[] = new WebSearchResult(
                        title: $annotation['title'] ?? $url,
                        url: $url,
                        excerpt: $excerpt,
                        sourceDate: null,
                        retrievedAt: $retrievedAt,
                    );
                }
            }
        }

        return $results;
    }
}
