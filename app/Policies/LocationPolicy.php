<?php

namespace App\Policies;

use App\Models\Location;
use App\Models\User;

/**
 * Locations are shared reference data (no hierarchy scoping) — managing them is gated by the
 * `settings.manage` permission.
 */
class LocationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Location $location): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->can('settings.manage');
    }

    public function update(User $user, Location $location): bool
    {
        return $user->can('settings.manage');
    }

    public function delete(User $user, Location $location): bool
    {
        return $user->can('settings.manage');
    }

    public function restore(User $user, Location $location): bool
    {
        return $user->can('settings.manage');
    }

    public function forceDelete(User $user, Location $location): bool
    {
        return $user->can('settings.manage');
    }
}
