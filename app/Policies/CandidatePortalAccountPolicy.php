<?php

namespace App\Policies;

use App\Models\CandidatePortalAccount;
use App\Models\User;

/**
 * Portal accounts are managed with portal.manage, limited to candidates the user can see
 * (CandidatePolicy). Accounts are never created or edited as records — only through
 * CandidatePortalService (invite / revoke).
 */
class CandidatePortalAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('portal.manage');
    }

    public function view(User $user, CandidatePortalAccount $candidatePortalAccount): bool
    {
        return $user->can('portal.manage') && $user->can('view', $candidatePortalAccount->candidate);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, CandidatePortalAccount $candidatePortalAccount): bool
    {
        return $this->view($user, $candidatePortalAccount);
    }

    public function delete(User $user, CandidatePortalAccount $candidatePortalAccount): bool
    {
        return false;
    }
}
