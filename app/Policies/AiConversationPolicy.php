<?php

namespace App\Policies;

use App\Models\AiConversation;
use App\Models\User;
use App\Services\AI\Privacy\AiConversationVisibility;

/**
 * A user always owns their own conversations (opened through the AI Copilot page, which restricts
 * lookups to the signed-in user's rows). Reviewing other users' conversations needs the dedicated
 * ai.conversations.view permission and is hierarchy-scoped (Phase 8.1) — ai.manage (knowledge
 * base, usage) and audit.view grant nothing here.
 */
class AiConversationPolicy
{
    public function __construct(private readonly AiConversationVisibility $visibility) {}

    public function viewAny(User $user): bool
    {
        return $this->visibility->canReview($user);
    }

    public function view(User $user, AiConversation $aiConversation): bool
    {
        return $this->visibility->canSee($user, $aiConversation->user_id);
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
        return $aiConversation->user_id === $user->id;
    }
}
