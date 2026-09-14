<?php

namespace App\Services\AI\Tools;

use App\Models\User;
use App\Services\AI\Actions\ConfirmationGate;
use App\Services\AI\DTO\ToolDefinition;
use App\Services\AI\Tools\Contracts\AiTool;

/**
 * Central registry of every AiTool the Copilot can call. Populated once at boot by
 * AiServiceProvider from ToolRegistrar::CLASSES. Permission filtering happens here so a user can
 * never even be offered a tool call they're not authorized for — the model never sees it, and
 * AiOrchestrator never executes it.
 *
 * Write/External/HighImpact tools are only offered when AI actions are enabled AND the user holds
 * ai.actions.execute — offering a user an action nobody in their session can approve only produces
 * dead-end pending cards.
 */
class ToolRegistry
{
    /**
     * @var array<string, AiTool>
     */
    private array $tools = [];

    public function register(AiTool $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function find(string $name): ?AiTool
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return array<int, AiTool>
     */
    public function all(): array
    {
        return array_values($this->tools);
    }

    /**
     * @return array<int, AiTool>
     */
    public function forUser(User $user): array
    {
        return array_values(array_filter(
            $this->tools,
            fn (AiTool $tool) => $this->isOfferedTo($user, $tool),
        ));
    }

    /**
     * @return array<int, ToolDefinition>
     */
    public function definitionsForUser(User $user): array
    {
        return array_map(
            fn (AiTool $tool) => new ToolDefinition($tool->name(), $tool->description(), $tool->inputSchema(), $tool->riskLevel(), $tool->permission()),
            $this->forUser($user),
        );
    }

    /**
     * Whether $user is allowed to invoke $toolName at all — re-checked by AiOrchestrator/
     * ActionExecutor right before execution, independent of what was offered to the model.
     */
    public function userMayUse(User $user, string $toolName): bool
    {
        $tool = $this->find($toolName);

        return $tool !== null && ($tool->permission() === null || $user->can($tool->permission()));
    }

    /**
     * Whether $tool would be included in the tool definitions sent to the model for $user.
     */
    public function isOfferedTo(User $user, AiTool|string $tool): bool
    {
        $tool = is_string($tool) ? $this->find($tool) : $tool;

        if ($tool === null || ! $this->userMayUse($user, $tool->name())) {
            return false;
        }

        if (! $tool->riskLevel()->requiresConfirmation()) {
            return true;
        }

        return (bool) config('ai.features.actions_enabled')
            && $user->can(ConfirmationGate::APPROVAL_PERMISSION);
    }
}
