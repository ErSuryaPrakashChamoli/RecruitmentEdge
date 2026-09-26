<?php

use App\Services\AI\Contracts\EmbeddingProviderInterface;
use App\Services\AI\Contracts\LLMProviderInterface;
use App\Services\AI\Contracts\WebSearchProviderInterface;
use App\Services\AI\DTO\LlmMessage;
use App\Services\AI\Gateway\AiGateway;
use App\Services\AI\Privacy\AiPrivacyViolationException;
use Tests\Feature\Ai\Fakes\RecordingEmbeddingProvider;
use Tests\Feature\Ai\Fakes\RecordingWebSearchProvider;
use Tests\Feature\Ai\Fakes\ScriptedLlmProvider;

beforeEach(function (): void {
    $this->llm = new ScriptedLlmProvider([], ['ok' => true]);
    $this->embeddings = new RecordingEmbeddingProvider;
    $this->search = new RecordingWebSearchProvider;
    app()->instance(LLMProviderInterface::class, $this->llm);
    app()->instance(EmbeddingProviderInterface::class, $this->embeddings);
    app()->instance(WebSearchProviderInterface::class, $this->search);
    config(['ai.features.web_search_enabled' => true]);
});

test('every gateway operation is guarded: a leaking payload never reaches the provider in block mode', function (string $operation): void {
    config(['ai.privacy.egress_mode' => 'block']);
    $gateway = app(AiGateway::class);
    $leak = [LlmMessage::user('Candidate phone 9999912345')];

    $call = match ($operation) {
        'generate' => fn () => $gateway->generate($leak, [], 'balanced'),
        'stream' => fn () => $gateway->stream($leak, [], 'balanced', fn () => null),
        'structured' => fn () => $gateway->structured($leak, ['type' => 'object']),
        'embed' => fn () => $gateway->embed(['Candidate phone 9999912345']),
        'research' => fn () => $gateway->research('Candidate phone 9999912345'),
    };

    expect($call)->toThrow(AiPrivacyViolationException::class)
        ->and($this->llm->calls)->toBe([])
        ->and($this->embeddings->texts)->toBe([])
        ->and($this->search->queries)->toBe([]);
})->with(['generate', 'stream', 'structured', 'embed', 'research']);

test('in redact mode the provider receives the cleaned payload, and research that needed cleaning is not sent at all', function (): void {
    config(['ai.privacy.egress_mode' => 'redact']);
    $gateway = app(AiGateway::class);

    $gateway->generate([LlmMessage::user('Mail PRIVATE-ALICE@example.invalid')], [], 'balanced');
    $gateway->embed(['Policy text; contact 9999912345']);
    $results = $gateway->research('PRIVATE-ALICE@example.invalid background');

    expect($this->llm->calls[0]['messages'][0]->content)->toBe('Mail [email removed]')
        ->and($this->embeddings->texts)->toBe(['Policy text; contact [phone removed]'])
        ->and($results)->toBe([])
        ->and($this->search->queries)->toBe([]);
});

test('clean payloads are unchanged in every operation', function (): void {
    $gateway = app(AiGateway::class);

    $gateway->structured([LlmMessage::user('Role: Senior Laravel Developer, 5-8 years')], ['type' => 'object']);
    $gateway->research('Senior Laravel Developer salary benchmarks Delhi 2026');

    expect($this->llm->calls[0]['messages'][0]->content)->toBe('Role: Senior Laravel Developer, 5-8 years')
        ->and($this->search->queries)->toBe(['Senior Laravel Developer salary benchmarks Delhi 2026']);
});
