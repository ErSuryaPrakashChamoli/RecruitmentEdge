<?php

namespace App\Policies;

use App\Models\Designation;
use App\Models\User;

/**
 * Designations are shared reference data (no hierarchy scoping) — managing them is gated by the
 * `settings.manage` permission.
 */
class DesignationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Designation $designation): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->can('settings.manage');
    }

    public function update(User $user, Designation $designation): bool
    {
        return $user->can('settings.manage');
    }

    /**
     * Archive (a soft delete) — only through MasterDataLifecycleService, which also asks for a
     * reason and refuses while open work still uses the record (Phase 8.6, D8.6-001/002).
     */
    public function delete(User $user, Designation $designation): bool
    {
        return $user->can('settings.manage');
    }

    public function restore(User $user, Designation $designation): bool
    {
        return $user->can('settings.manage');
    }

    /**
     * Never: master data is archived, not permanently deleted — historical records refer to it.
     */
    public function forceDelete(User $user, Designation $designation): bool
    {
        return false;
    }

    /**
     * No bulk archive, restore or delete: each change needs its own reason and in-use check.
     */
    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }
}
