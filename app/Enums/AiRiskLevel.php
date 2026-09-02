<?php

namespace App\Enums;

/**
 * Governs whether an AI tool call executes immediately or must be queued for human confirmation.
 * Read/Recommend never touch the database. Write/External/HighImpact always create a `pending`
 * ai_tool_calls row and require ActionExecutor + ai.actions.execute — never executed directly off
 * a model tool-call, regardless of what the model claims. See AiOrchestrator, ConfirmationGate.
 */
enum AiRiskLevel: string
{
    case Read = 'read';
    case Recommend = 'recommend';
    case Write = 'write';
    case External = 'external';
    case HighImpact = 'high_impact';

    public function label(): string
    {
        return match ($this) {
            self::Read => 'Read',
            self::Recommend => 'Recommend',
            self::Write => 'Write',
            self::External => 'External',
            self::HighImpact => 'High Impact',
        };
    }

    /**
     * Whether a tool at this risk level requires explicit human approval before executing.
     */
    public function requiresConfirmation(): bool
    {
        return match ($this) {
            self::Read, self::Recommend => false,
            self::Write, self::External, self::HighImpact => true,
        };
    }
}
