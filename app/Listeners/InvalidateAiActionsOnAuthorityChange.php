<?php

namespace App\Listeners;

use App\Events\EmployeeAccessRevoked;
use App\Events\EmployeeAccessSuspended;
use App\Events\UserRoleChanged;
use App\Services\AI\Actions\ActionExecutor;

/**
 * Phase 8.4: when a person loses access or their roles change, every AI action they proposed and
 * nobody has decided yet is invalidated — it can never run on authority they no longer hold.
 * Synchronous on purpose (it runs right after the change commits). ActionExecutor::approve also
 * re-checks the requester's authority, so this is the tidy-up, not the only guard.
 */
class InvalidateAiActionsOnAuthorityChange
{
    public function __construct(private readonly ActionExecutor $executor) {}

    public function handleSuspended(EmployeeAccessSuspended $event): void
    {
        $this->executor->invalidatePendingFor($event->userId, 'requester_suspended');
    }

    public function handleRevoked(EmployeeAccessRevoked $event): void
    {
        $this->executor->invalidatePendingFor($event->userId, 'requester_revoked');
    }

    public function handleRoleChanged(UserRoleChanged $event): void
    {
        $this->executor->invalidatePendingFor($event->userId, 'requester_roles_changed');
    }
}
