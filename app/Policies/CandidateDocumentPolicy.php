<?php

namespace App\Policies;

use App\Models\CandidateDocument;
use App\Models\User;
use App\Services\HierarchyService;

/**
 * Two kinds of document share this model:
 * - joining documents (candidate_joining_id set) are governed by `joining.confirm` and scoped by
 *   the joining application's recruiter;
 * - candidate-level documents (no joining yet) follow the candidate's own permissions and
 *   hierarchy scoping, delegated to CandidatePolicy.
 */
class CandidateDocumentPolicy
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function viewAny(User $user): bool
    {
        return $user->canAny(['joining.confirm', 'candidates.viewAny']);
    }

    public function view(User $user, CandidateDocument $candidateDocument): bool
    {
        if ($this->isJoiningDocument($candidateDocument)) {
            return $user->can('joining.confirm') && $this->isJoiningInScope($user, $candidateDocument);
        }

        return $candidateDocument->candidate !== null && $user->can('view', $candidateDocument->candidate);
    }

    public function create(User $user): bool
    {
        return $user->canAny(['joining.confirm', 'candidates.update']);
    }

    public function update(User $user, CandidateDocument $candidateDocument): bool
    {
        if ($this->isJoiningDocument($candidateDocument)) {
            return $user->can('joining.confirm') && $this->isJoiningInScope($user, $candidateDocument);
        }

        return $candidateDocument->candidate !== null && $user->can('update', $candidateDocument->candidate);
    }

    public function delete(User $user, CandidateDocument $candidateDocument): bool
    {
        return ! $this->isJoiningDocument($candidateDocument) && $this->update($user, $candidateDocument);
    }

    private function isJoiningDocument(CandidateDocument $candidateDocument): bool
    {
        return $candidateDocument->candidate_joining_id !== null;
    }

    private function isJoiningInScope(User $user, CandidateDocument $candidateDocument): bool
    {
        return $this->hierarchy->canView($user, $candidateDocument->candidateJoining->candidateApplication->recruiter);
    }
}
