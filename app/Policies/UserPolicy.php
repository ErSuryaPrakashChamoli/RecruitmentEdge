<?php

namespace App\Policies;

use App\Models\User;

/**
 * Creating logins and assigning roles is an HR-admin function gated by `users.manage` — it is
 * deliberately not hierarchy-scoped, since granting a login is independent of where someone sits
 * in the reporting tree.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('users.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function update(User $user, User $model): bool
    {
        return $user->can('users.manage');
    }

    public function delete(User $user, User $model): bool
    {
        return $user->can('users.manage') && $user->isNot($model);
    }
}
