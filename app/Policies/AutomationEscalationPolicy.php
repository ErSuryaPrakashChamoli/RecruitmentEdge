<?php

namespace App\Policies;

use App\Models\AutomationEscalation;
use App\Models\User;

/**
 * Escalations are part of execution history: visible with automation.executions (scoped through
 * their execution), stoppable with automation.escalations.
 */
class AutomationEscalationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('automation.executions');
    }

    public function view(User $user, AutomationEscalation $escalation): bool
    {
        return $escalation->execution !== null && $user->can('view', $escalation->execution);
    }

    public function stop(User $user, AutomationEscalation $escalation): bool
    {
        return $user->can('automation.escalations') && $this->view($user, $escalation);
    }

    public function update(User $user, AutomationEscalation $escalation): bool
    {
        return false;
    }

    public function delete(User $user, AutomationEscalation $escalation): bool
    {
        return false;
    }
}
