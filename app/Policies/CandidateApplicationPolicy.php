<?php

namespace App\Policies;

use App\Models\CandidateApplication;
use App\Models\User;
use App\Policies\Concerns\ForbidsDeletion;
use App\Services\HierarchyService;

class CandidateApplicationPolicy
{
    use ForbidsDeletion;

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

    /**
     * Phase 8.3: moving an application to another requisition is an explicit, audited operation
     * (ApplicationAssignmentService::moveToRequisition) — never an ordinary edit.
     */
    public function move(User $user, CandidateApplication $candidateApplication): bool
    {
        return $this->update($user, $candidateApplication) && $this->transitionStage($user, $candidateApplication);
    }

    public function reassign(User $user, CandidateApplication $candidateApplication): bool
    {
        return $user->can('candidates.reassign') && $this->hierarchy->canView($user, $candidateApplication->recruiter);
    }
}
