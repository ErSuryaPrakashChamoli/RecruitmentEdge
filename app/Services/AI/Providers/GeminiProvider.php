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
use stdClass;
use Throwable;

/**
 * Google Gemini adapter, built on Laravel's Http client against the documented REST endpoints
 * (no vendor SDK) — the current (verified against ai.google.dev, Aug 2026) generateContent API for
 * chat/tool-calling/structured output, the google_search grounding tool for web research, and
 * batchEmbedContents for embeddings. Auth via the `x-goog-api-key` header (the currently documented
 * mechanism — not the older `?key=` query-string form, which would leak into request logs/URLs).
 *
 * Message-role mapping differs fundamentally from OpenAI: Gemini has no "system"/"tool" roles in
 * `contents` — system prompts go in a separate `systemInstruction` field, assistant turns are role
 * "model", and function results are sent back as "user" turns containing `functionResponse` parts
 * (see toGeminiContents()). Gemini 3+ models return a stable `id` on each functionCall for
 * request/response correlation; Gemini 2.5 does not, so parallel tool calls from one turn are
 * answered by bundling their functionResponse parts into a single following "user" content entry,
 * in the same order the functionCalls arrived — the documented fallback correlation strategy when
 * no id is present.
 *
 * Streaming note: as with OpenAiProvider, stream() does not parse the live `alt=sse` event stream
 * (unverified exact framing without a live key to test against) — it completes the call and
 * flushes the result in word-sized chunks through the same $onDelta contract.
 */
class GeminiProvider implements EmbeddingProviderInterface, LLMProviderInterface, WebSearchProviderInterface
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $baseUrl,
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

        $payload = $this->basePayload($messages, $options);

        if ($tools !== []) {
            $payload['tools'] = [[
                'functionDeclarations' => array_map(fn (ToolDefinition $tool) => $this->toFunctionDeclaration($tool), $tools),
            ]];
        }

        $response = $this->request()->post("/models/{$model}:generateContent", $payload);

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

        $payload = $this->basePayload($messages, $options);
        $payload['generationConfig']['responseMimeType'] = 'application/json';
        $payload['generationConfig']['responseSchema'] = $jsonSchema['schema'] ?? $jsonSchema;

        try {
            $response = $this->request()->post("/models/{$model}:generateContent", $payload);
            $text = $this->extractText($response->json() ?? []);

            return $text !== null ? (json_decode($text, true) ?? []) : [];
        } catch (Throwable $e) {
            Log::error('Gemini structured() call failed', ['exception' => $e->getMessage()]);

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

        $model ??= config('ai.embeddings.model');
        $taskType = $context === 'query' ? 'RETRIEVAL_QUERY' : 'RETRIEVAL_DOCUMENT';
        $dimensions = (int) config('ai.embeddings.dimensions');

        $response = $this->request()->post("/models/{$model}:batchEmbedContents", [
            'requests' => array_map(fn (string $text) => array_filter([
                'model' => "models/{$model}",
                'content' => ['parts' => [['text' => $text]]],
                'taskType' => $taskType,
                // gemini-embedding-001 supports Matryoshka truncation via outputDimensionality —
                // without it the API defaults to 3072, which still works with VectorSearch's
                // cosine similarity (dimension-agnostic) but wastes storage/compute vs. the
                // configured 1536. Only sent when a positive value is configured.
                'outputDimensionality' => $dimensions > 0 ? $dimensions : null,
            ], fn ($value) => $value !== null), array_values($texts)),
        ]);

        if ($response->failed()) {
            Log::error('Gemini embed() call failed', ['status' => $response->status(), 'body' => $response->body()]);

            throw new AiProviderUnavailableException('The Gemini embedding service returned an error.');
        }

        return collect($response->json('embeddings') ?? [])
            ->map(fn (array $embedding) => array_map('floatval', $embedding['values'] ?? []))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<int, WebSearchResult>
     */
    public function search(string $query, array $options = []): array
    {
        $this->assertConfigured();

        $model = $options['model'] ?? config('ai.models.balanced');

        $response = $this->request()->post("/models/{$model}:generateContent", [
            'contents' => [['role' => 'user', 'parts' => [['text' => $query]]]],
            'tools' => [['google_search' => new stdClass]],
        ]);

        if ($response->failed()) {
            Log::error('Gemini web search failed', ['status' => $response->status(), 'body' => $response->body()]);

            throw new AiProviderUnavailableException('The Gemini web search service returned an error.');
        }

        return $this->extractCitations($response->json() ?? []);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->timeout(60)
            ->withHeaders(['x-goog-api-key' => (string) $this->apiKey])
            ->acceptJson();
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new AiProviderUnavailableException('No GEMINI_API_KEY is configured.');
        }
    }

    /**
     * @param  array<int, LlmMessage>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function basePayload(array $messages, array $options): array
    {
        $systemText = collect($messages)
            ->filter(fn (LlmMessage $m) => $m->role === 'system')
            ->map(fn (LlmMessage $m) => $m->content)
            ->filter()
            ->implode("\n\n");

        $payload = [
            'contents' => $this->toGeminiContents($messages),
            'generationConfig' => [
                'maxOutputTokens' => $options['max_tokens'] ?? config('ai.limits.max_tokens'),
            ],
        ];

        if ($systemText !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $systemText]]];
        }

        return $payload;
    }

    private function toFunctionDeclaration(ToolDefinition $tool): array
    {
        return [
            'name' => $tool->name,
            'description' => $tool->description,
            'parameters' => $this->normalizeSchemaForGemini($tool->parameters),
        ];
    }

    /**
     * Gemini's schema validator requires `properties` to be a JSON object even when there are no
     * properties — a tool with no arguments (e.g. FindAtRiskRequisitionsTool) declares
     * `'properties' => []` in PHP, which json_encode() serializes as `[]` (a list), not `{}`,
     * causing a live 400 "Cannot bind a list to map for field 'properties'" (caught by testing
     * against a real key, not something the docs would have flagged). Force it to an object.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function normalizeSchemaForGemini(array $schema): array
    {
        if (isset($schema['properties']) && $schema['properties'] === []) {
            $schema['properties'] = new stdClass;
        }

        return $schema;
    }

    /**
     * @param  array<int, LlmMessage>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function toGeminiContents(array $messages): array
    {
        $contents = [];
        $pendingFunctionResponses = [];

        $flushPending = function () use (&$contents, &$pendingFunctionResponses): void {
            if ($pendingFunctionResponses !== []) {
                $contents[] = ['role' => 'user', 'parts' => $pendingFunctionResponses];
                $pendingFunctionResponses = [];
            }
        };

        foreach ($messages as $message) {
            if ($message->role === 'system') {
                continue; // handled separately as systemInstruction
            }

            if ($message->role === 'tool') {
                $part = [
                    'functionResponse' => [
                        'name' => $message->toolName ?? 'unknown_tool',
                        'response' => ['result' => json_decode((string) $message->content, true) ?? $message->content],
                    ],
                ];

                if ($message->toolCallId !== null && str_starts_with($message->toolCallId, 'gemini-call-') === false) {
                    $part['functionResponse']['id'] = $message->toolCallId;
                }

                $pendingFunctionResponses[] = $part;

                continue;
            }

            $flushPending();

            if ($message->role === 'assistant' && filled($message->toolCalls)) {
                $parts = [];

                if (filled($message->content)) {
                    $parts[] = ['text' => $message->content];
                }

                foreach ($message->toolCalls as $call) {
                    $functionCall = ['name' => $call['name'], 'args' => $call['arguments'] ?? []];

                    if (! str_starts_with((string) $call['id'], 'gemini-call-')) {
                        $functionCall['id'] = $call['id'];
                    }

                    $part = ['functionCall' => $functionCall];

                    // thoughtSignature (camelCase on the wire) is a SIBLING of functionCall in the
                    // part, not nested inside it, and must be replayed verbatim or Gemini 3
                    // reasoning models return a 400 (caught live — see class docblock).
                    $thoughtSignature = $call['metadata']['thought_signature'] ?? null;

                    if ($thoughtSignature !== null) {
                        $part['thoughtSignature'] = $thoughtSignature;
                    }

                    $parts[] = $part;
                }

                $contents[] = ['role' => 'model', 'parts' => $parts];

                continue;
            }

            $contents[] = [
                'role' => $message->role === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => (string) $message->content]],
            ];
        }

        $flushPending();

        return $contents;
    }

    private function parseResponse(Response $response, string $model): LlmResponse
    {
        if ($response->failed()) {
            Log::error('Gemini complete() call failed', ['status' => $response->status(), 'body' => $response->body()]);

            throw new RuntimeException('The Gemini service returned an error response.');
        }

        $json = $response->json() ?? [];
        $parts = $json['candidates'][0]['content']['parts'] ?? [];

        $content = $this->extractText($json);
        $toolCalls = [];

        foreach ($parts as $part) {
            if (isset($part['functionCall'])) {
                $toolCalls[] = [
                    // Gemini 2.5 doesn't return a call id; synthesize a stable one purely for our
                    // own storage/correlation — recognizable by the "gemini-call-" prefix so
                    // toGeminiContents() knows not to send a fabricated id back to Gemini.
                    'id' => $part['functionCall']['id'] ?? ('gemini-call-'.bin2hex(random_bytes(6))),
                    'name' => $part['functionCall']['name'] ?? '',
                    'arguments' => $part['functionCall']['args'] ?? [],
                    // thoughtSignature sits as a sibling of functionCall within the same part, not
                    // nested inside it, and is camelCase on the wire — despite the API's own 400
                    // error text spelling it "thought_signature" when missing (verified against a
                    // real response, not the error message's wording). Must be captured and
                    // replayed verbatim on the next turn or Gemini 3 models reject the request.
                    'metadata' => isset($part['thoughtSignature']) ? ['thought_signature' => $part['thoughtSignature']] : null,
                ];
            }
        }

        $usage = $json['usageMetadata'] ?? [];

        return new LlmResponse(
            content: $content,
            toolCalls: $toolCalls,
            usage: [
                'input_tokens' => (int) ($usage['promptTokenCount'] ?? 0),
                'output_tokens' => (int) ($usage['candidatesTokenCount'] ?? 0),
                'cached_tokens' => (int) ($usage['cachedContentTokenCount'] ?? 0),
            ],
            model: $json['modelVersion'] ?? $model,
        );
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function extractText(array $json): ?string
    {
        $parts = $json['candidates'][0]['content']['parts'] ?? [];

        $text = collect($parts)
            ->filter(fn (array $part) => isset($part['text']))
            ->map(fn (array $part) => $part['text'])
            ->implode('');

        return $text === '' ? null : $text;
    }

    /**
     * Extracts source citations from Gemini's grounding metadata. Wrapped defensively: an
     * unexpected shape returns no results rather than fabricating or crashing — same philosophy as
     * OpenAiProvider::extractCitations().
     *
     * @param  array<string, mixed>  $json
     * @return array<int, WebSearchResult>
     */
    private function extractCitations(array $json): array
    {
        try {
            $chunks = $json['candidates'][0]['groundingMetadata']['groundingChunks'] ?? [];
            $retrievedAt = CarbonImmutable::now();

            return collect($chunks)
                ->map(function (array $chunk) use ($retrievedAt) {
                    $web = $chunk['web'] ?? null;

                    if ($web === null || blank($web['uri'] ?? null)) {
                        return null;
                    }

                    return new WebSearchResult(
                        title: $web['title'] ?? $web['uri'],
                        url: $web['uri'],
                        excerpt: null,
                        sourceDate: null,
                        retrievedAt: $retrievedAt,
                    );
                })
                ->filter()
                ->values()
                ->all();
        } catch (Throwable $e) {
            Log::error('Gemini citation parsing failed', ['exception' => $e->getMessage()]);

            return [];
        }
    }
}
