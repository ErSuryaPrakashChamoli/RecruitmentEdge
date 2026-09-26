<?php

namespace App\Policies;

use App\Models\CandidateCommunication;
use App\Models\User;

/**
 * A message is visible to users who can see its candidate (CandidatePolicy's hierarchy scope) and
 * hold communications.view; the Communication Center list applies the same scope in its query.
 * Messages are records of what was sent — never edited or deleted.
 */
class CandidateCommunicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('communications.view');
    }

    public function view(User $user, CandidateCommunication $candidateCommunication): bool
    {
        return $user->can('communications.view') && $user->can('view', $candidateCommunication->candidate);
    }

    public function create(User $user): bool
    {
        return $user->can('communications.send');
    }

    public function update(User $user, CandidateCommunication $candidateCommunication): bool
    {
        return false;
    }

    public function delete(User $user, CandidateCommunication $candidateCommunication): bool
    {
        return false;
    }
}
