<?php

namespace Tests\Feature\Ai\Fakes;

use App\Services\AI\Contracts\LLMProviderInterface;
use App\Services\AI\Contracts\ReportsUsage;
use App\Services\AI\DTO\LlmMessage;
use App\Services\AI\DTO\LlmResponse;
use App\Services\AI\DTO\ToolDefinition;
use Throwable;

/**
 * A configured, network-free LLM provider that replays a scripted queue of responses (or throws a
 * scripted exception) and records every call's model id, so tests can assert routing and usage
 * logging without a real API key.
 */
class ScriptedLlmProvider implements LLMProviderInterface, ReportsUsage
{
    /**
     * @var array<int, array{method: string, model: string, messages: array<int, LlmMessage>, tools: array<int, ToolDefinition>}>
     */
    public array $calls = [];

    /**
     * @param  array<int, LlmResponse|Throwable>  $responses
     * @param  array<string, mixed>  $structuredResult
     * @param  array{input_tokens?: int, output_tokens?: int, cached_tokens?: int}  $structuredUsage
     */
    public function __construct(
        private array $responses = [],
        private readonly array $structuredResult = [],
        private readonly array $structuredUsage = [],
    ) {}

    public static function text(string $content, int $inputTokens = 100, int $outputTokens = 50): LlmResponse
    {
        return new LlmResponse($content, [], ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens, 'cached_tokens' => 0], 'scripted');
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function toolCall(string $name, array $arguments = []): LlmResponse
    {
        return new LlmResponse(null, [['id' => 'call_'.uniqid(), 'name' => $name, 'arguments' => $arguments]], ['input_tokens' => 10, 'output_tokens' => 5, 'cached_tokens' => 0], 'scripted');
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function complete(array $messages, array $tools, string $model, array $options = []): LlmResponse
    {
        $this->calls[] = ['method' => 'complete', 'model' => $model, 'messages' => $messages, 'tools' => $tools];

        $next = array_shift($this->responses) ?? self::text('Done.');

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }

    public function stream(array $messages, array $tools, string $model, callable $onDelta, array $options = []): LlmResponse
    {
        $response = $this->complete($messages, $tools, $model, $options);
        $onDelta((string) $response->content);

        return $response;
    }

    public function structured(array $messages, string $model, array $jsonSchema, array $options = []): array
    {
        $this->calls[] = ['method' => 'structured', 'model' => $model, 'messages' => $messages, 'tools' => []];

        return $this->structuredResult;
    }

    public function lastUsage(): array
    {
        return $this->structuredUsage;
    }

    /**
     * @return array<int, string>
     */
    public function modelsCalled(): array
    {
        return array_column($this->calls, 'model');
    }
}
