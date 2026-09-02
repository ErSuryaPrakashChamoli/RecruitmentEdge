<?php

namespace App\Services\AI\DTO;

/**
 * Normalized result of a single LLMProviderInterface call, independent of provider wire format.
 */
final class LlmResponse
{
    /**
     * @param  array<int, array{id: string, name: string, arguments: array<string, mixed>, metadata?: array<string, mixed>|null}>  $toolCalls  `metadata` carries opaque provider-specific round-trip data (e.g. Gemini's thought_signature) — providers that don't need it simply omit the key
     * @param  array{input_tokens: int, output_tokens: int, cached_tokens: int}  $usage
     */
    public function __construct(
        public readonly ?string $content,
        public readonly array $toolCalls,
        public readonly array $usage,
        public readonly string $model,
        public readonly bool $configured = true,
    ) {}

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }

    public static function unconfigured(string $message, string $model): self
    {
        return new self(
            content: $message,
            toolCalls: [],
            usage: ['input_tokens' => 0, 'output_tokens' => 0, 'cached_tokens' => 0],
            model: $model,
            configured: false,
        );
    }
}
