<?php

namespace App\Policies;

use App\Models\CandidateSource;
use App\Models\User;

/**
 * Candidate sources are shared reference data (no hierarchy scoping) — managing them is gated by
 * the `settings.manage` permission.
 */
class CandidateSourcePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CandidateSource $candidateSource): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->can('settings.manage');
    }

    public function update(User $user, CandidateSource $candidateSource): bool
    {
        return $user->can('settings.manage');
    }

    public function delete(User $user, CandidateSource $candidateSource): bool
    {
        return $user->can('settings.manage');
    }

    public function restore(User $user, CandidateSource $candidateSource): bool
    {
        return $user->can('settings.manage');
    }

    public function forceDelete(User $user, CandidateSource $candidateSource): bool
    {
        return $user->can('settings.manage');
    }

    /**
     * Bulk actions (Phase 8.6 discovery security guard): explicit, never Filament's missing-method
     * fallback. Each selected record is still checked against the per-record rule.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('settings.manage');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('settings.manage');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('settings.manage');
    }
}
