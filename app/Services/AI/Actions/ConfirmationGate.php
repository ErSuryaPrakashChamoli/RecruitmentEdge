<?php

namespace App\Services\AI\Actions;

use App\Models\User;
use App\Services\AI\Tools\Contracts\AiTool;

/**
 * Small policy wrapper around AiRiskLevel::requiresConfirmation() — kept separate from the enum so
 * approval authority (who may click Approve) lives in one obvious place alongside it.
 */
class ConfirmationGate
{
    public const string APPROVAL_PERMISSION = 'ai.actions.execute';

    public function requiresConfirmation(AiTool $tool): bool
    {
        return $tool->riskLevel()->requiresConfirmation();
    }

    public function canApprove(User $user): bool
    {
        return $user->can(self::APPROVAL_PERMISSION);
    }
}
