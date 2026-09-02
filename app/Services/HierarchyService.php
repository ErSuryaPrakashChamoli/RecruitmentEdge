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

    /**
     * Team size below (not including) the given employee.
     */
    public function teamSizeOf(int $employeeId): int
    {
        return $this->descendantIdsOf($employeeId)->count() - 1;
    }

    /**
     * A nested org-chart structure rooted at $rootEmployeeId, for the Organization/Hierarchy
     * admin page (Section 32). Built from exactly 3 queries total (the employee rows, their team
     * sizes, and the closure table for grouping) regardless of subtree depth or size — avoids the
     * N+1 a naive recursive-query implementation would produce.
     *
     * @return array{employee: Employee, team_size: int, children: array<int, array<string, mixed>>}|null
     */
    public function treeFor(int $rootEmployeeId): ?array
    {
        $descendantIds = $this->descendantIdsOf($rootEmployeeId);

        if ($descendantIds->isEmpty()) {
            return null;
        }

        $employees = Employee::query()
            ->whereIn('id', $descendantIds)
            ->with(['designation', 'department'])
            ->get()
            ->keyBy('id');

        $teamSizes = DB::table('employee_hierarchy')
            ->whereIn('ancestor_id', $descendantIds)
            ->selectRaw('ancestor_id, count(*) - 1 as team_size')
            ->groupBy('ancestor_id')
            ->pluck('team_size', 'ancestor_id');

        $byManager = $employees->groupBy('reports_to_id');

        $build = function (int $id) use (&$build, $employees, $byManager, $teamSizes): ?array {
            $employee = $employees->get($id);

            if ($employee === null) {
                return null;
            }

            return [
                'employee' => $employee,
                'team_size' => (int) ($teamSizes[$id] ?? 0),
                'children' => $byManager->get($id, collect())
                    ->map(fn (Employee $child) => $build($child->id))
                    ->filter()
                    ->values()
                    ->all(),
            ];
        };

        return $build($rootEmployeeId);
    }
}
