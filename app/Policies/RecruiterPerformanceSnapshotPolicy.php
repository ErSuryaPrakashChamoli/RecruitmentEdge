<?php

namespace App\Policies;

use App\Models\RecruiterPerformanceSnapshot;
use App\Models\User;
use App\Services\HierarchyService;

/**
 * Snapshots are read-mostly and recomputable — there is no create/update from the UI, only
 * viewing and triggering a recalculation (gated the same as viewing).
 */
class RecruiterPerformanceSnapshotPolicy
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function viewAny(User $user): bool
    {
        return $user->can('performance.view');
    }

    public function view(User $user, RecruiterPerformanceSnapshot $recruiterPerformanceSnapshot): bool
    {
        return $user->can('performance.view') && $this->hierarchy->canView($user, $recruiterPerformanceSnapshot->employee);
    }

    public function recalculate(User $user, RecruiterPerformanceSnapshot $recruiterPerformanceSnapshot): bool
    {
        return $this->view($user, $recruiterPerformanceSnapshot);
    }
}
