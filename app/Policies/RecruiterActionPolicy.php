<?php

namespace App\Policies;

use App\Models\RecruiterAction;
use App\Models\User;
use App\Services\HierarchyService;

/**
 * Action Center items belong to their owner. The owner and anyone above them in the hierarchy may
 * see an item; the owner works it, and managers with actions.manage may also work, assign and
 * reassign items inside their team. Items are closed, never deleted.
 */
class RecruiterActionPolicy
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function viewAny(User $user): bool
    {
        return $user->employee_id !== null || $user->can('hierarchy.view-all');
    }

    public function view(User $user, RecruiterAction $action): bool
    {
        return $action->owner !== null && $this->hierarchy->canView($user, $action->owner);
    }

    public function create(User $user): bool
    {
        return $user->can('actions.manage');
    }

    public function update(User $user, RecruiterAction $action): bool
    {
        if ($action->owner_id !== null && $action->owner_id === $user->employee_id) {
            return true;
        }

        return $user->can('actions.manage') && $this->view($user, $action);
    }

    public function delete(User $user, RecruiterAction $action): bool
    {
        return false;
    }
}
