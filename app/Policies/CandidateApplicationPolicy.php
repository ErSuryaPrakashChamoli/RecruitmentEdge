<?php

namespace App\Policies;

use App\Models\CandidateApplication;
use App\Models\User;
use App\Services\HierarchyService;

class CandidateApplicationPolicy
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function viewAny(User $user): bool
    {
        return $user->can('candidates.viewAny');
    }

    public function view(User $user, CandidateApplication $candidateApplication): bool
    {
        return $user->can('candidates.viewAny') && $this->hierarchy->canView($user, $candidateApplication->recruiter);
    }

    public function create(User $user): bool
    {
        return $user->can('candidates.create');
    }

    public function update(User $user, CandidateApplication $candidateApplication): bool
    {
        return $user->can('candidates.update') && $this->hierarchy->canView($user, $candidateApplication->recruiter);
    }

    public function transitionStage(User $user, CandidateApplication $candidateApplication): bool
    {
        return $user->can('pipeline.transition') && $this->hierarchy->canView($user, $candidateApplication->recruiter);
    }

    public function reassign(User $user, CandidateApplication $candidateApplication): bool
    {
        return $user->can('candidates.reassign') && $this->hierarchy->canView($user, $candidateApplication->recruiter);
    }
}
