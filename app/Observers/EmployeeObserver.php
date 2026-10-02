<?php

namespace App\Observers;

use App\Models\Employee;
use App\Services\HierarchyMemo;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the `employee_hierarchy` closure table in sync with `employees.reports_to_id`.
 *
 * The closure table stores one row per (ancestor, descendant) pair at any depth — including a
 * depth-0 self-reference — so "everyone under me" / "everyone above me" queries are a single
 * indexed lookup instead of a recursive walk, at any org depth.
 */
class EmployeeObserver
{
    /**
     * Phase 8.3: the reporting hierarchy is the access boundary, so it must stay a tree. An employee
     * can never report to themselves or to anyone in their own subtree. Phase 8.4: every write goes
     * through HierarchyIntegrityService (reports_to_id is guarded), which checks under row locks;
     * this remains the model-level backstop. A soft delete leaves the closure rows in place on
     * purpose — the deleted person's history stays visible to the managers above them.
     */
    public function updating(Employee $employee): void
    {
        if (! $employee->isDirty('reports_to_id') || $employee->reports_to_id === null) {
            return;
        }

        $cycle = (int) $employee->reports_to_id === $employee->id
            || DB::table('employee_hierarchy')->where('ancestor_id', $employee->id)->where('descendant_id', $employee->reports_to_id)->exists();

        if ($cycle) {
            throw new DomainException("{$employee->fullName()} cannot report to someone in their own reporting line.");
        }
    }

    public function created(Employee $employee): void
    {
        DB::transaction(function () use ($employee): void {
            DB::table('employee_hierarchy')->insert([
                'ancestor_id' => $employee->id,
                'descendant_id' => $employee->id,
                'depth' => 0,
                'created_at' => now(),
            ]);

            $this->attachToParent($employee);
        });

        app(HierarchyMemo::class)->flush();
    }

    public function updated(Employee $employee): void
    {
        if (! $employee->wasChanged('reports_to_id')) {
            return;
        }

        DB::transaction(function () use ($employee): void {
            $subtree = DB::table('employee_hierarchy')
                ->where('ancestor_id', $employee->id)
                ->get(['descendant_id', 'depth']);

            $subtreeIds = $subtree->pluck('descendant_id');

            // Detach the moved subtree from every one of its old ancestors (outside the subtree itself).
            DB::table('employee_hierarchy')
                ->whereIn('descendant_id', $subtreeIds)
                ->whereNotIn('ancestor_id', $subtreeIds)
                ->delete();

            $this->attachToParent($employee, $subtree);
        });

        // Phase 8.9: visibility follows the new reporting line at once (HierarchyMemo).
        app(HierarchyMemo::class)->flush();
    }

    /**
     * Re-link an employee (and, on a move, its existing subtree) under its current `reports_to_id`.
     *
     * @param  Collection<int, object{descendant_id: int, depth: int}>|null  $subtree  Rows already known to be below
     *                                                                                 $employee (defaults to just itself).
     */
    private function attachToParent(Employee $employee, ?Collection $subtree = null): void
    {
        if ($employee->reports_to_id === null) {
            return;
        }

        $subtree ??= collect([(object) ['descendant_id' => $employee->id, 'depth' => 0]]);

        $newAncestors = DB::table('employee_hierarchy')
            ->where('descendant_id', $employee->reports_to_id)
            ->get(['ancestor_id', 'depth']);

        $rows = [];

        foreach ($newAncestors as $ancestor) {
            foreach ($subtree as $descendant) {
                $rows[] = [
                    'ancestor_id' => $ancestor->ancestor_id,
                    'descendant_id' => $descendant->descendant_id,
                    'depth' => $ancestor->depth + $descendant->depth + 1,
                    'created_at' => now(),
                ];
            }
        }

        if ($rows !== []) {
            DB::table('employee_hierarchy')->insert($rows);
        }
    }
}
