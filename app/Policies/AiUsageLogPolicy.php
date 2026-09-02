<?php

namespace App\Policies;

use App\Models\AiUsageLog;
use App\Models\User;

/**
 * Read-only usage/cost tracking, gated by ai.manage (spec section 40).
 */
class AiUsageLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ai.manage');
    }

    public function view(User $user, AiUsageLog $aiUsageLog): bool
    {
        return $user->can('ai.manage');
    }
}
