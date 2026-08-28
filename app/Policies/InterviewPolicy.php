<?php

namespace App\Policies;

use App\Models\Interview;
use App\Models\User;
use App\Services\HierarchyService;

class InterviewPolicy
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function viewAny(User $user): bool
    {
        return $user->can('interviews.manage');
    }

    public function view(User $user, Interview $interview): bool
    {
        return $user->can('interviews.manage') && $this->isInScope($user, $interview);
    }

    public function create(User $user): bool
    {
        return $user->can('interviews.manage');
    }

    public function update(User $user, Interview $interview): bool
    {
        return $user->can('interviews.manage') && $this->isInScope($user, $interview);
    }

    private function isInScope(User $user, Interview $interview): bool
    {
        $visible = $this->hierarchy->visibleEmployeeIdsFor($user);

        if ($visible === null) {
            return true;
        }

        return $visible->contains($interview->candidateApplication->recruiter_id)
            || $visible->contains($interview->interviewer_id);
    }
}
