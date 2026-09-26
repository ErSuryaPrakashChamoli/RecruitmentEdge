<?php

namespace App\Policies;

use App\Models\CandidateCommunicationPreference;
use App\Models\User;

/**
 * Preferences are changed through CommunicationPreferenceService by users with
 * communications.preferences who can see the candidate.
 */
class CandidateCommunicationPreferencePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('communications.preferences');
    }

    public function view(User $user, CandidateCommunicationPreference $candidateCommunicationPreference): bool
    {
        return $user->can('communications.preferences') && $user->can('view', $candidateCommunicationPreference->candidate);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, CandidateCommunicationPreference $candidateCommunicationPreference): bool
    {
        return $this->view($user, $candidateCommunicationPreference);
    }

    public function delete(User $user, CandidateCommunicationPreference $candidateCommunicationPreference): bool
    {
        return false;
    }
}
