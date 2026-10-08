<?php

namespace Tests\Feature\Ai\Fakes;

use App\Services\AI\Contracts\LLMProviderInterface;
use App\Services\AI\Contracts\ReportsUsage;
use App\Services\AI\DTO\LlmMessage;
use App\Services\AI\DTO\LlmResponse;

/**
 * Network-free provider for the Phase 8.1 privacy contract tests. The first chat call that is
 * offered tools asks for the one tool under test; every other call — including tools' own model
 * calls (drafting, summarising, planning) — gets a fixed reply. Every provider-bound message is
 * recorded so tests can inspect exactly what would have left the application.
 */
class PrivacyRecordingLlmProvider implements LLMProviderInterface, ReportsUsage
{
    /**
     * @var array<int, array<int, LlmMessage>>
     */
    public array $calls = [];

    private bool $toolCallIssued = false;

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(private readonly string $toolName, private readonly array $arguments) {}

    public function isConfigured(): bool
    {
        return true;
    }

    public function complete(array $messages, array $tools, string $model, array $options = []): LlmResponse
    {
        $this->calls[] = $messages;

        if ($tools !== [] && ! $this->toolCallIssued) {
            $this->toolCallIssued = true;

            return new LlmResponse(null, [['id' => 'call_privacy', 'name' => $this->toolName, 'arguments' => $this->arguments]], $this->lastUsage(), 'privacy-fake');
        }

        return new LlmResponse("Subject: Next steps\n\nHi {{candidate.first_name}}, thank you — we will be in touch.", [], $this->lastUsage(), 'privacy-fake');
    }

    public function stream(array $messages, array $tools, string $model, callable $onDelta, array $options = []): LlmResponse
    {
        $response = $this->complete($messages, $tools, $model, $options);
        $onDelta((string) $response->content);

        return $response;
    }

    public function structured(array $messages, string $model, array $jsonSchema, array $options = []): array
    {
        $this->calls[] = $messages;

        return [];
    }

    public function lastUsage(): array
    {
        return ['input_tokens' => 1, 'output_tokens' => 1, 'cached_tokens' => 0];
    }

    /**
     * Everything that would have been sent to the provider, as one string.
     */
    public function payload(): string
    {
        return (string) json_encode(array_map(
            fn (array $messages) => array_map(fn (LlmMessage $m) => [$m->role, $m->content, $m->toolCalls], $messages),
            $this->calls,
        ));
    }
}
