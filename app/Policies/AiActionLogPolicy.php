<?php

namespace App\Policies;

use App\Models\AiActionLog;
use App\Models\User;

/**
 * Read-only AI action audit trail, gated by ai.manage (spec section 41).
 */
class AiActionLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ai.manage');
    }

    public function view(User $user, AiActionLog $aiActionLog): bool
    {
        return $user->can('ai.manage');
    }
}
