<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Read-only, gated by `audit.view`. Not hierarchy-scoped: audit entries span heterogeneous
 * model types (Candidate, Employee, User, target/performance/incentive rules) with no single
 * consistent "owning recruiter" column to scope by, so oversight access is permission-only.
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('audit.view');
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $user->can('audit.view');
    }
}
