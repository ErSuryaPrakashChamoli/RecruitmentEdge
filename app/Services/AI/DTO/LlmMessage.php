<?php

namespace App\Services\AI\DTO;

/**
 * One turn in a provider conversation. Distinct from the persisted App\Models\AiMessage — this is
 * the wire-format value object passed to/from LLMProviderInterface.
 */
final class LlmMessage
{
    /**
     * @param  array<int, array{id: string, name: string, arguments: array<string, mixed>}>|null  $toolCalls
     */
    public function __construct(
        public readonly string $role,
        public readonly ?string $content = null,
        public readonly ?array $toolCalls = null,
        public readonly ?string $toolCallId = null,
        public readonly ?string $toolName = null,
    ) {}

    public static function system(string $content): self
    {
        return new self('system', $content);
    }

    public static function user(string $content): self
    {
        return new self('user', $content);
    }

    public static function assistant(?string $content, ?array $toolCalls = null): self
    {
        return new self('assistant', $content, $toolCalls);
    }

    public static function tool(string $toolCallId, string $content, ?string $toolName = null): self
    {
        return new self('tool', $content, null, $toolCallId, $toolName);
    }
}
