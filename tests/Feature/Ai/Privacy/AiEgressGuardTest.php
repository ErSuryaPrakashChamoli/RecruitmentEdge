<?php

use App\Services\AI\DTO\LlmMessage;
use App\Services\AI\Privacy\AiEgressGuard;
use App\Services\AI\Privacy\AiPrivacyViolationException;
use App\Services\AI\Privacy\AiSensitiveValues;
use Illuminate\Support\Facades\Log;

test('in redact mode personal data is removed from every part of a message and only counts are logged', function (): void {
    config(['ai.privacy.egress_mode' => 'redact']);
    Log::spy();
    app(AiSensitiveValues::class)->add('PRIVATE-CANDIDATE-ALICE');

    $messages = app(AiEgressGuard::class)->messages([
        LlmMessage::system('Viewing PRIVATE-CANDIDATE-ALICE'),
        LlmMessage::assistant(null, [['id' => 'c1', 'name' => 'send', 'arguments' => ['remarks' => 'mail PRIVATE-ALICE@example.invalid']]]),
        LlmMessage::tool('c1', json_encode(['email' => 'PRIVATE-ALICE@example.invalid', 'stage' => 'Screened', 'note' => 'call 9999912345']), 'get_candidate'),
    ], 'generate');

    $payload = json_encode(array_map(fn (LlmMessage $m) => [$m->content, $m->toolCalls], $messages));

    expect($payload)->not->toContain('PRIVATE-CANDIDATE-ALICE')->not->toContain('PRIVATE-ALICE@example.invalid')->not->toContain('9999912345')
        ->and($messages[2]->toolCallId)->toBe('c1')
        ->and($messages[2]->toolName)->toBe('get_candidate')
        ->and(json_decode($messages[2]->content, true))->toBe(['stage' => 'Screened', 'note' => 'call [phone removed]'])
        ->and($messages[1]->toolCalls[0]['arguments'])->toHaveKey('remarks');

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $context['operation'] === 'generate'
        && ! str_contains(json_encode($context), 'PRIVATE'))->once();
});

test('in block mode a leak fails loudly without echoing the value', function (): void {
    config(['ai.privacy.egress_mode' => 'block']);

    expect(fn () => app(AiEgressGuard::class)->texts(['Contact PRIVATE-ALICE@example.invalid'], 'embed'))
        ->toThrow(AiPrivacyViolationException::class, 'Provider-bound embed payload contained personal data: email.');
});

test('configuration cannot switch the guard off', function (): void {
    config(['ai.privacy.egress_mode' => 'off']);

    expect(AiEgressGuard::mode())->toBe(AiEgressGuard::MODE_REDACT)
        ->and(app(AiEgressGuard::class)->query('salary for 9999912345', 'research'))->toBe('salary for [phone removed]');
});

test('clean payloads pass through untouched', function (): void {
    config(['ai.privacy.egress_mode' => 'block']);

    expect(app(AiEgressGuard::class)->query('Senior Laravel Developer salary benchmarks Delhi 2026', 'research'))
        ->toBe('Senior Laravel Developer salary benchmarks Delhi 2026');
});
