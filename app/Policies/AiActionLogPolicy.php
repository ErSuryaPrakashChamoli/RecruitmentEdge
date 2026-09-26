<?php

namespace App\Policies;

use App\Models\AiActionLog;
use App\Models\User;
use App\Services\AI\Privacy\AiConversationVisibility;

/**
 * Read-only AI action audit trail (spec section 41). Since Phase 8.1 it follows the same rule as
 * conversation review: ai.conversations.view, limited to the reviewer's hierarchy.
 */
class AiActionLogPolicy
{
    public function __construct(private readonly AiConversationVisibility $visibility) {}

    public function viewAny(User $user): bool
    {
        return $this->visibility->canReview($user);
    }

    public function view(User $user, AiActionLog $aiActionLog): bool
    {
        return $this->visibility->canReview($user) && $this->visibility->canSee($user, $aiActionLog->user_id);
    }
}
