<?php

namespace App\Listeners;

use App\Events\EmployeeAccessRevoked;
use App\Events\EmployeeAccessSuspended;
use App\Jobs\ProcessOwnershipHandoffJob;
use App\Models\User;

/**
 * Phase 8.4: a suspension or revocation starts the handoff of the person's current work (queued).
 * One handoff per loss of access: the key includes the time the access state changed.
 */
class StartOwnershipHandoff
{
    public function handleSuspended(EmployeeAccessSuspended $event): void
    {
        $this->dispatch($event->userId, $event->employeeId, 'suspended');
    }

    public function handleRevoked(EmployeeAccessRevoked $event): void
    {
        $this->dispatch($event->userId, $event->employeeId, 'revoked:'.$event->source);
    }

    private function dispatch(int $userId, ?int $employeeId, string $trigger): void
    {
        $changedAt = User::query()->whereKey($userId)->value('access_changed_at');

        ProcessOwnershipHandoffJob::dispatch($userId, $employeeId, $trigger, "{$userId}:{$trigger}:".strtotime((string) $changedAt));
    }
}
