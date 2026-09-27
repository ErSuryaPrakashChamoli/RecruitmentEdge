<?php

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 8.6 (D8.6-027): an immutable history record (stage history, offer status history,
 * approval trails, automation versions, distribution attempts, duplicate decisions). It is shown
 * only inside its owner record's page — whoever may open the owner may read it — and is never
 * created, edited or deleted through the panel. Declared explicitly so Filament's strict mode (and
 * the production fail-closed gate) never has to guess.
 */
trait ReadOnlyRecord
{
    use ForbidsDeletion;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Model $model): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $model): bool
    {
        return false;
    }
}
