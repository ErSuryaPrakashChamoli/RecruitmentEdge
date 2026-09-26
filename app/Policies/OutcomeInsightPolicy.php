<?php

namespace App\Policies;

use App\Models\OutcomeInsight;
use App\Models\User;

/**
 * Outcome Loop insights are organisation-wide aggregates (counts, never a person), reviewed by
 * holders of outcomes.review. They are only ever accepted, rejected or deferred — never edited
 * or deleted.
 */
class OutcomeInsightPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('outcomes.review');
    }

    public function view(User $user, OutcomeInsight $insight): bool
    {
        return $user->can('outcomes.review');
    }

    public function review(User $user, OutcomeInsight $insight): bool
    {
        return $user->can('outcomes.review') && $insight->status->isOpen();
    }

    public function update(User $user, OutcomeInsight $insight): bool
    {
        return false;
    }

    public function delete(User $user, OutcomeInsight $insight): bool
    {
        return false;
    }
}
