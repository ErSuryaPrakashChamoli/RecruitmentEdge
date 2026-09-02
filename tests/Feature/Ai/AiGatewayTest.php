<?php

use App\Models\AiUsageLog;
use App\Services\AI\DTO\LlmMessage;
use App\Services\AI\Gateway\AiGateway;

test('with no api key configured, the gateway degrades to a clear unconfigured message instead of erroring', function (): void {
    $gateway = app(AiGateway::class);

    expect($gateway->isConfigured())->toBeFalse();

    $response = $gateway->generate([LlmMessage::user('hello')], [], 'balanced');

    expect($response->configured)->toBeFalse()
        ->and($response->content)->toContain('not configured')
        ->and($response->hasToolCalls())->toBeFalse();
});

test('every generate() call is logged to ai_usage_logs regardless of outcome', function (): void {
    $gateway = app(AiGateway::class);

    expect(AiUsageLog::query()->count())->toBe(0);

    $gateway->generate([LlmMessage::user('hello')], [], 'balanced');

    $log = AiUsageLog::query()->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->request_type->value)->toBe('chat')
        ->and($log->provider)->toBe(config('ai.provider'));
});

test('web research returns no results when web search is disabled, without touching the provider', function (): void {
    config(['ai.features.web_search_enabled' => false]);

    $gateway = app(AiGateway::class);

    expect($gateway->research('Laravel developer salary Delhi'))->toBe([]);
});
