<?php

namespace App\Services\AI\Actions;

use App\Models\User;
use App\Services\HierarchyService;

/**
 * Phase 8.4: a fingerprint of a person's authority — their roles, their employee record, whether
 * they see everything, and their own position in the hierarchy (the managers above them). Taken
 * when an AI action is proposed and compared at approval: a demotion, a role change, a move to
 * another part of the organisation or a relink invalidates the action rather than running it on
 * authority the person no longer has. Growth of their team does not (it grants nothing new to an
 * action already proposed); a target they can no longer see is refused by the tool itself, which
 * re-scopes every record to the executing user (ScopesToHierarchy).
 */
class ApprovalAuthority
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function fingerprint(User $user): string
    {
        return hash('sha256', (string) json_encode([
            'user' => (int) $user->getKey(),
            'roles' => $user->roles()->pluck('roles.id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
            'employee' => $user->employee_id !== null ? (int) $user->employee_id : null,
            'sees_all' => $this->hierarchy->visibleEmployeeIdsFor($user) === null,
            'position' => $user->employee_id !== null ? $this->hierarchy->ancestorIdsOf((int) $user->employee_id)->map(fn ($id) => (int) $id)->sort()->values()->all() : [],
        ]));
    }
}
