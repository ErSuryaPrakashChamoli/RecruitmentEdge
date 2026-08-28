<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single choke point for hierarchy-based visibility (CHRO -> VP HR -> Manager -> Assistant
 * Manager -> Recruiter). Every policy and query scope that restricts recruitment data to "my
 * team" should resolve its allowed employee IDs through this service rather than walking
 * `reports_to_id` by hand.
 */
class HierarchyService
{
    /**
     * The employee IDs a user is allowed to see (themselves plus everyone below them in the
     * hierarchy), or null when the user holds the `hierarchy.view-all` permission and therefore
     * has no restriction at all — callers should skip filtering entirely in that case rather than
     * loading every employee ID.
     *
     * @return Collection<int, int>|null
     */
    public function visibleEmployeeIdsFor(User $user): ?Collection
    {
        if ($user->can('hierarchy.view-all')) {
            return null;
        }

        if ($user->employee_id === null) {
            return collect();
        }

        return $this->descendantIdsOf($user->employee_id);
    }

    /**
     * Whether $subject is $user's own employee record or lies within their reporting hierarchy.
     */
    public function canView(User $user, Employee $subject): bool
    {
        $visible = $this->visibleEmployeeIdsFor($user);

        return $visible === null || $visible->contains($subject->id);
    }

    /**
     * Employee IDs at or below the given employee (inclusive), via the closure table.
     *
     * @return Collection<int, int>
     */
    public function descendantIdsOf(int $employeeId): Collection
    {
        return DB::table('employee_hierarchy')
            ->where('ancestor_id', $employeeId)
            ->pluck('descendant_id');
    }
}
