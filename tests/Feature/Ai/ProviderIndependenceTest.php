<?php

use App\Enums\AiRiskLevel;
use App\Services\AI\Contracts\EmbeddingProviderInterface;
use App\Services\AI\Contracts\LLMProviderInterface;
use App\Services\AI\Contracts\WebSearchProviderInterface;
use App\Services\AI\DTO\LlmMessage;
use App\Services\AI\DTO\ToolDefinition;
use App\Services\AI\Exceptions\AiProviderUnavailableException;
use App\Services\AI\Providers\GeminiProvider;
use App\Services\AI\Providers\NullProvider;
use App\Services\AI\Providers\OpenAiProvider;
use Illuminate\Support\Facades\Http;

/**
 * Proves the core provider-agnostic requirement: GeminiProvider, OpenAiProvider, and NullProvider
 * all satisfy the same three interfaces, and AiGateway/the rest of the app receives an identical
 * normalized LlmResponse regardless of which one is behind it. All external calls are mocked via
 * Http::fake() — no real API key is required to run this suite.
 */
test('Gemini, OpenAI, and Null providers all satisfy the same three capability interfaces', function (): void {
    $gemini = new GeminiProvider('fake-key', 'https://generativelanguage.googleapis.com/v1beta');
    $openai = new OpenAiProvider('fake-key', 'https://api.openai.com/v1');
    $null = new NullProvider;

    foreach ([$gemini, $openai, $null] as $provider) {
        expect($provider)->toBeInstanceOf(LLMProviderInterface::class)
            ->and($provider)->toBeInstanceOf(EmbeddingProviderInterface::class)
            ->and($provider)->toBeInstanceOf(WebSearchProviderInterface::class);
    }
});

test('GeminiProvider normalizes a plain text response into the same LlmResponse shape as any other provider', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [['text' => 'Hello from Gemini']]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 12, 'candidatesTokenCount' => 5, 'cachedContentTokenCount' => 0, 'totalTokenCount' => 17],
            'modelVersion' => 'gemini-2.5-flash',
        ]),
    ]);

    $provider = new GeminiProvider('fake-key', 'https://generativelanguage.googleapis.com/v1beta');
    $response = $provider->complete([LlmMessage::user('hi')], [], 'gemini-2.5-flash');

    expect($response->content)->toBe('Hello from Gemini')
        ->and($response->hasToolCalls())->toBeFalse()
        ->and($response->usage)->toBe(['input_tokens' => 12, 'output_tokens' => 5, 'cached_tokens' => 0])
        ->and($response->configured)->toBeTrue();

    Http::assertSent(fn ($request) => $request->hasHeader('x-goog-api-key', 'fake-key'));
});

test('GeminiProvider parses a functionCall into a normalized tool call the orchestrator can execute', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => [
                    'role' => 'model',
                    'parts' => [[
                        'functionCall' => ['name' => 'search_candidates', 'args' => ['query' => 'Java developer']],
                    ]],
                ],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 20, 'candidatesTokenCount' => 8, 'totalTokenCount' => 28],
        ]),
    ]);

    $provider = new GeminiProvider('fake-key', 'https://generativelanguage.googleapis.com/v1beta');
    $response = $provider->complete([LlmMessage::user('find java developers')], [], 'gemini-2.5-flash');

    expect($response->hasToolCalls())->toBeTrue()
        ->and($response->toolCalls[0]['name'])->toBe('search_candidates')
        ->and($response->toolCalls[0]['arguments'])->toBe(['query' => 'Java developer'])
        ->and($response->toolCalls[0]['id'])->toStartWith('gemini-call-');
});

test('OpenAiProvider normalizes a Responses API reply into the same LlmResponse shape', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response([
            'output' => [[
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => 'Hello from OpenAI']],
            ]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 4, 'input_tokens_details' => ['cached_tokens' => 0]],
            'model' => 'gpt-5.6-terra',
        ]),
    ]);

    $provider = new OpenAiProvider('fake-key', 'https://api.openai.com/v1');
    $response = $provider->complete([LlmMessage::user('hi')], [], 'gpt-5.6-terra');

    expect($response->content)->toBe('Hello from OpenAI')
        ->and($response->usage)->toBe(['input_tokens' => 10, 'output_tokens' => 4, 'cached_tokens' => 0]);
});

test('GeminiProvider embed() calls batchEmbedContents and returns normalized vectors', function (): void {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'embeddings' => [
                ['values' => [0.1, 0.2, 0.3]],
                ['values' => [0.4, 0.5, 0.6]],
            ],
        ]),
    ]);

    $provider = new GeminiProvider('fake-key', 'https://generativelanguage.googleapis.com/v1beta');
    $vectors = $provider->embed(['first chunk', 'second chunk'], 'gemini-embedding-001');

    expect($vectors)->toBe([[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]]);

    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_contains($request->url(), ':batchEmbedContents')
            && $body['requests'][0]['taskType'] === 'RETRIEVAL_DOCUMENT';
    });
});

test('GeminiProvider embed() requests RETRIEVAL_QUERY task type when embedding a search query', function (): void {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['embeddings' => [['values' => [1.0]]]])]);

    $provider = new GeminiProvider('fake-key', 'https://generativelanguage.googleapis.com/v1beta');
    $provider->embed(['find java developers'], 'gemini-embedding-001', 'query');

    Http::assertSent(fn ($request) => $request->data()['requests'][0]['taskType'] === 'RETRIEVAL_QUERY');
});

test('GeminiProvider serializes an empty tool parameter schema as a JSON object, not an array', function (): void {
    // Regression test for a real bug caught against a live key: PHP's `'properties' => []`
    // json_encode()s as `[]` (a list), which Gemini's schema validator rejects with "Cannot bind
    // a list to map for field 'properties'" — a no-argument tool (e.g. FindAtRiskRequisitionsTool)
    // triggers this on every request unless normalizeSchemaForGemini() forces it to `{}`.
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'ok']]]]],
        'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
    ])]);

    $provider = new GeminiProvider('fake-key', 'https://generativelanguage.googleapis.com/v1beta');
    $noArgTool = new ToolDefinition(
        name: 'find_at_risk_requisitions',
        description: 'test',
        parameters: ['type' => 'object', 'properties' => []],
        riskLevel: AiRiskLevel::Read,
    );

    $provider->complete([LlmMessage::user('hi')], [$noArgTool], 'gemini-3.6-flash');

    Http::assertSent(function ($request) {
        $body = (string) $request->body();

        return str_contains($body, '"properties":{}') && ! str_contains($body, '"properties":[]');
    });
});

test('GeminiProvider captures thoughtSignature from a functionCall part and replays it verbatim on the next turn', function (): void {
    // Regression test for a real bug caught live: Gemini 3 models require the exact thoughtSignature
    // from a prior functionCall to be replayed on the next turn (a sibling field of functionCall in
    // the part, camelCase on the wire — the API's own 400 error text misleadingly spells it
    // "thought_signature" when missing). Without it, every second turn of a tool-calling
    // conversation fails.
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [[
                    'functionCall' => ['name' => 'search_candidates', 'args' => ['query' => 'Zubin'], 'id' => 'call_1'],
                    'thoughtSignature' => 'opaque-signature-value',
                ]]],
            ]],
            'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
        ]),
    ]);

    $provider = new GeminiProvider('fake-key', 'https://generativelanguage.googleapis.com/v1beta');
    $firstResponse = $provider->complete([LlmMessage::user('find Zubin')], [], 'gemini-3.6-flash');

    expect($firstResponse->toolCalls[0]['metadata'])->toBe(['thought_signature' => 'opaque-signature-value']);

    // Now replay it as history, as ConversationContextBuilder would on the next orchestrator turn.
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'done']]]]],
        'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
    ])]);

    $provider->complete([
        LlmMessage::user('find Zubin'),
        LlmMessage::assistant(null, $firstResponse->toolCalls),
        LlmMessage::tool('call_1', json_encode(['success' => true]), 'search_candidates'),
    ], [], 'gemini-3.6-flash');

    Http::assertSent(fn ($request) => str_contains((string) $request->body(), '"thoughtSignature":"opaque-signature-value"'));
});

test('an unconfigured GeminiProvider throws when asked to embed rather than silently returning fake vectors', function (): void {
    $provider = new GeminiProvider(null, 'https://generativelanguage.googleapis.com/v1beta');

    expect($provider->isConfigured())->toBeFalse()
        ->and(fn () => $provider->embed(['text']))->toThrow(AiProviderUnavailableException::class);
});
