<?php

namespace App\Services\AI\DTO;

use App\Enums\AiRiskLevel;

/**
 * The wire-format declaration of one AiTool sent to the provider as a function tool, plus the
 * metadata AiOrchestrator/ActionExecutor need that never gets sent to the model.
 */
final class ToolDefinition
{
    /**
     * @param  array<string, mixed>  $parameters  JSON schema for the tool's arguments
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $parameters,
        public readonly AiRiskLevel $riskLevel,
        public readonly ?string $permission = null,
    ) {}

    /**
     * @return array{type: string, name: string, description: string, parameters: array<string, mixed>, strict: bool}
     */
    public function toProviderSchema(): array
    {
        return [
            'type' => 'function',
            'name' => $this->name,
            'description' => $this->description,
            'parameters' => $this->parameters,
            'strict' => false,
        ];
    }
}
