<?php

namespace App\Policies;

use App\Models\AiKnowledgeArticle;
use App\Models\User;

class AiKnowledgeArticlePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ai.query');
    }

    public function view(User $user, AiKnowledgeArticle $aiKnowledgeArticle): bool
    {
        return $user->can('ai.query');
    }

    public function create(User $user): bool
    {
        return $user->can('ai.manage');
    }

    public function update(User $user, AiKnowledgeArticle $aiKnowledgeArticle): bool
    {
        return $user->can('ai.manage');
    }

    public function delete(User $user, AiKnowledgeArticle $aiKnowledgeArticle): bool
    {
        return $user->can('ai.manage');
    }

    /**
     * Bulk actions (Phase 8.6 discovery security guard): explicit, never Filament's missing-method
     * fallback. Each selected record is still checked against the per-record rule.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('ai.manage');
    }
}
