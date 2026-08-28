<?php

namespace App\Observers;

use App\Models\Employee;
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
