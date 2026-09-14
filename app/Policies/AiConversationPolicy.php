<?php

namespace App\Policies;

use App\Models\AiConversation;
use App\Models\User;

/**
 * A user always owns their own conversations (opened through the AI Copilot page, which restricts
 * lookups to the signed-in user's rows); the AI Conversations review resource — listing everyone's
 * conversations — is for administrators with ai.manage only (spec section 32).
 */
class AiConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ai.manage');
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
