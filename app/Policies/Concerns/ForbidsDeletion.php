<?php

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Security containment (Phase 8.6 discovery): hiring facts are never deleted through the UI —
 * they change only through their lifecycle services (reject, withdraw, cancel, dropout).
 *
 * The methods must exist: Filament (not in strict mode) treats a missing policy method as
 * allowed, so without them its Delete / ForceDelete / Restore actions ran for any user who could
 * open the record, even though Gate::can() said no.
 */
trait ForbidsDeletion
{
    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $model): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Model $model): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }
}
