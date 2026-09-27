<?php

namespace App\Policies;

use App\Models\CandidateJoining;
use App\Models\User;
use App\Policies\Concerns\ForbidsDeletion;
use App\Services\HierarchyService;

class CandidateJoiningPolicy
{
    use ForbidsDeletion;

    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function viewAny(User $user): bool
    {
        return $user->can('joining.confirm');
    }

    public function view(User $user, CandidateJoining $candidateJoining): bool
    {
        return $user->can('joining.confirm') && $this->isInScope($user, $candidateJoining);
    }

    public function update(User $user, CandidateJoining $candidateJoining): bool
    {
        return $user->can('joining.confirm') && $this->isInScope($user, $candidateJoining);
    }

    /**
     * Phase 8.3: converting a joined candidate into an employee needs the dedicated
     * employees.convert permission (not users.manage) and the joining in the actor's hierarchy.
     */
    public function convert(User $user, CandidateJoining $candidateJoining): bool
    {
        return $user->can('employees.convert') && $this->isInScope($user, $candidateJoining);
    }

    private function isInScope(User $user, CandidateJoining $candidateJoining): bool
    {
        return $this->hierarchy->canView($user, $candidateJoining->candidateApplication->recruiter);
    }
}
