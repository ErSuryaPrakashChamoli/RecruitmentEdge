<?php

namespace App\Services\AI\Contracts;

use App\Services\AI\DTO\LlmMessage;
use App\Services\AI\DTO\LlmResponse;
use App\Services\AI\DTO\ToolDefinition;

interface LLMProviderInterface
{
    public function isConfigured(): bool;

    /**
     * @param  array<int, LlmMessage>  $messages
     * @param  array<int, ToolDefinition>  $tools
     * @param  array<string, mixed>  $options
     */
    public function complete(array $messages, array $tools, string $model, array $options = []): LlmResponse;

    /**
     * Streams the final answer to $onDelta as it becomes available and returns the same normalized
     * response complete() would. $onDelta receives one string chunk at a time.
     *
     * @param  array<int, LlmMessage>  $messages
     * @param  array<int, ToolDefinition>  $tools
     * @param  array<string, mixed>  $options
     */
    public function stream(array $messages, array $tools, string $model, callable $onDelta, array $options = []): LlmResponse;

    /**
     * Structured-output call constrained to $jsonSchema (json_schema mode). Returns the decoded
     * payload, or an empty array when the provider is unconfigured.
     *
     * @param  array<int, LlmMessage>  $messages
     * @param  array<string, mixed>  $jsonSchema
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function structured(array $messages, string $model, array $jsonSchema, array $options = []): array;
}
