<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Identity\AuthorityGuard;

/**
 * Creating logins and assigning roles is an HR-admin function gated by `users.manage`.
 *
 * Phase 8.4: an administrator never edits their own login here (their profile page covers their
 * own name, email and password), edits only logins inside their hierarchy, and never deletes a
 * login — access is revoked instead, so history and attribution stay intact. The escalation rules
 * themselves live in RoleAssignmentService / IdentityProvisioningService.
 */
class UserPolicy
{
    public function __construct(private readonly AuthorityGuard $authority) {}

    public function viewAny(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('users.manage') && $model->isMemberOfCurrentTenant();
    }

    public function create(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function update(User $user, User $model): bool
    {
        return $user->can('users.manage') && $user->isNot($model) && $model->isMemberOfCurrentTenant() && $this->authority->inScope($user, $model);
    }

    public function delete(User $user, User $model): bool
    {
        return false;
    }
}
