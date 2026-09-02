<?php

namespace App\Services\AI\Tools\Contracts;

use App\Enums\AiRiskLevel;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;

/**
 * One capability the Copilot can invoke. Every implementation must be a thin wrapper over an
 * EXISTING application service/query — tools never recompute business logic or write to the
 * database directly (spec sections 11/18/25). $arguments come from the model and MUST be
 * validated/resolved against the acting user's own visibility (HierarchyService/policies) inside
 * handle() — never trust an id just because the model supplied it.
 */
interface AiTool
{
    /**
     * Machine name sent to the provider as the function name. snake_case, globally unique.
     */
    public function name(): string;

    public function description(): string;

    /**
     * JSON schema for the tool's arguments, sent to the provider so it knows how to call this tool.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    public function riskLevel(): AiRiskLevel;

    /**
     * Spatie permission string required to see/use this tool, or null if every authenticated user
     * with base Copilot access (ai.query) may use it.
     */
    public function permission(): ?string;

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function handle(array $arguments, User $user): ToolResult;
}
