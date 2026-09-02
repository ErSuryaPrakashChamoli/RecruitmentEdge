<?php

namespace App\Policies;

use App\Models\AiConversation;
use App\Models\User;

/**
 * A user always owns/can view their own conversations (base ai.query access); ai.manage is for
 * administrators browsing conversations for support/audit purposes (spec section 32).
 */
class AiConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ai.query');
    }

    public function view(User $user, AiConversation $aiConversation): bool
    {
        return $aiConversation->user_id === $user->id || $user->can('ai.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('ai.query');
    }

    public function update(User $user, AiConversation $aiConversation): bool
    {
        return $aiConversation->user_id === $user->id;
    }

    public function delete(User $user, AiConversation $aiConversation): bool
    {
        return $aiConversation->user_id === $user->id || $user->can('ai.manage');
    }
}
