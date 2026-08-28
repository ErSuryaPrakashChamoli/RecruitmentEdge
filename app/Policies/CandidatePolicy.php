<?php

namespace App\Policies;

use App\Models\Candidate;
use App\Models\User;
use App\Services\HierarchyService;

/**
 * A candidate isn't owned by one recruiter (they can have applications to several requisitions,
 * each with its own recruiter) — so visibility is "does the viewer's hierarchy include the
 * recruiter on at least one of this candidate's applications", not a single FK check.
 */
class CandidatePolicy
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function viewAny(User $user): bool
    {
        return $user->can('candidates.viewAny');
    }

    public function view(User $user, Candidate $candidate): bool
    {
        return $user->can('candidates.viewAny') && $this->isInScope($user, $candidate);
    }

    public function create(User $user): bool
    {
        return $user->can('candidates.create');
    }

    public function update(User $user, Candidate $candidate): bool
    {
        return $user->can('candidates.update') && $this->isInScope($user, $candidate);
    }

    private function isInScope(User $user, Candidate $candidate): bool
    {
        $visible = $this->hierarchy->visibleEmployeeIdsFor($user);

        if ($visible === null) {
            return true;
        }

        $candidateRecruiterIds = $candidate->applications()->pluck('recruiter_id');

        return $visible->intersect($candidateRecruiterIds)->isNotEmpty()
            || ($user->employee_id !== null && $candidate->created_by === $user->employee_id);
    }
}
