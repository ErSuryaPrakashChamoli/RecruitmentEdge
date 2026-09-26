<?php

namespace App\Services\AI\Actions;

use App\Models\User;
use App\Services\HierarchyService;

/**
 * Phase 8.4: a fingerprint of a person's authority — their roles, employee record and the exact
 * set of employees they can see. Taken when an AI action is proposed and compared at approval: if
 * any of it changed (demotion, a role change, a reporting-line move, suspension), the action is
 * invalidated rather than run with authority the person no longer has.
 */
class ApprovalAuthority
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function fingerprint(User $user): string
    {
        $visible = $this->hierarchy->visibleEmployeeIdsFor($user);

        return hash('sha256', (string) json_encode([
            'user' => (int) $user->getKey(),
            'roles' => $user->roles()->pluck('roles.id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
            'employee' => $user->employee_id !== null ? (int) $user->employee_id : null,
            'scope' => $visible === null ? 'all' : $visible->map(fn ($id) => (int) $id)->sort()->values()->all(),
        ]));
    }
}
