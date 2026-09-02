<?php

namespace App\Policies;

use App\Models\AiDocument;
use App\Models\User;

class AiDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ai.manage');
    }

    public function view(User $user, AiDocument $aiDocument): bool
    {
        return $user->can('ai.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('ai.manage');
    }

    public function update(User $user, AiDocument $aiDocument): bool
    {
        return $user->can('ai.manage');
    }

    public function delete(User $user, AiDocument $aiDocument): bool
    {
        return $user->can('ai.manage');
    }
}
