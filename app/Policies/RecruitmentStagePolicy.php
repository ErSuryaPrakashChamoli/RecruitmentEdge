<?php

namespace App\Policies;

use App\Models\RecruitmentStage;
use App\Models\User;

/**
 * Hiring pipeline configuration is organisation-wide reference data (no hierarchy scoping) —
 * managing it is gated by the `pipeline.configure` permission. Deletion is never offered: stages
 * and templates are deactivated instead so snapshots and history stay resolvable.
 */
class RecruitmentStagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('pipeline.configure');
    }

    public function view(User $user, RecruitmentStage $recruitmentStage): bool
    {
        return $user->can('pipeline.configure');
    }

    public function create(User $user): bool
    {
        return $user->can('pipeline.configure');
    }

    public function update(User $user, RecruitmentStage $recruitmentStage): bool
    {
        return $user->can('pipeline.configure');
    }

    public function delete(User $user, RecruitmentStage $recruitmentStage): bool
    {
        return false;
    }
}
