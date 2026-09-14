<?php

namespace App\Services\AI\Tools\Concerns;

use App\Models\Candidate;
use App\Models\User;
use App\Services\HierarchyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Every tool touching candidate/requisition/recruiter data must resolve visibility through
 * HierarchyService — exactly like the Filament UI does — rather than inventing separate "AI
 * scoping" rules (spec section 27).
 */
trait ScopesToHierarchy
{
    /**
     * @return Collection<int, int>|null null means unrestricted (hierarchy.view-all)
     */
    protected function visibleEmployeeIds(User $user): ?Collection
    {
        return app(HierarchyService::class)->visibleEmployeeIdsFor($user);
    }

    /**
     * Restricts a Candidate query to the same rows CandidateResource/CandidatePolicy allow: a
     * recruiter on one of the candidate's applications is in the user's hierarchy, or the user
     * created the candidate themselves.
     *
     * @param  Builder<Candidate>  $query
     * @return Builder<Candidate>
     */
    protected function scopeCandidatesVisibleTo(Builder $query, User $user): Builder
    {
        $visibleIds = $this->visibleEmployeeIds($user);

        if ($visibleIds === null) {
            return $query;
        }

        return $query->where(function (Builder $scoped) use ($visibleIds, $user): void {
            $scoped->whereHas('applications', fn (Builder $applications) => $applications->whereIn('recruiter_id', $visibleIds));

            if ($user->employee_id !== null) {
                $scoped->orWhere('created_by', $user->employee_id);
            }
        });
    }

    /**
     * Restricts any query over a model with a `recruiter_id` column (applications, follow-ups) to
     * recruiters within the user's hierarchy.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function scopeRecruiterOwnedTo(Builder $query, User $user, string $column = 'recruiter_id'): Builder
    {
        $visibleIds = $this->visibleEmployeeIds($user);

        return $visibleIds === null ? $query : $query->whereIn($column, $visibleIds);
    }
}
