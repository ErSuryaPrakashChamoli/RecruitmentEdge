<?php

namespace App\Policies;

use App\Models\AutomationExecution;
use App\Models\User;
use App\Services\HierarchyService;

/**
 * Execution history is visible with automation.executions, limited to runs about the viewer's team
 * (runs with no recruiter — requisition-level — only to users who see everything). Retrying and
 * cancelling need automation.retry. Runs are never edited or deleted by hand.
 */
class AutomationExecutionPolicy
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function viewAny(User $user): bool
    {
        return $user->can('automation.executions');
    }

    public function view(User $user, AutomationExecution $execution): bool
    {
        if (! $user->can('automation.executions')) {
            return false;
        }

        $visible = $this->hierarchy->visibleEmployeeIdsFor($user);

        return $visible === null || ($execution->recruiter_id !== null && $visible->contains($execution->recruiter_id));
    }

    public function retry(User $user, AutomationExecution $execution): bool
    {
        return $user->can('automation.retry') && $this->view($user, $execution);
    }

    public function update(User $user, AutomationExecution $execution): bool
    {
        return false;
    }

    public function delete(User $user, AutomationExecution $execution): bool
    {
        return false;
    }
}
