<?php

namespace App\Policies;

use App\Models\CandidateDocument;
use App\Models\User;
use App\Services\HierarchyService;

class CandidateDocumentPolicy
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function viewAny(User $user): bool
    {
        return $user->can('joining.confirm');
    }

    public function view(User $user, CandidateDocument $candidateDocument): bool
    {
        return $user->can('joining.confirm') && $this->isInScope($user, $candidateDocument);
    }

    public function create(User $user): bool
    {
        return $user->can('joining.confirm');
    }

    public function update(User $user, CandidateDocument $candidateDocument): bool
    {
        return $user->can('joining.confirm') && $this->isInScope($user, $candidateDocument);
    }

    private function isInScope(User $user, CandidateDocument $candidateDocument): bool
    {
        return $this->hierarchy->canView($user, $candidateDocument->candidateJoining->candidateApplication->recruiter);
    }
}
