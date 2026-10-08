<?php

use App\Models\Employee;
use App\Models\User;
use App\Services\HierarchyMemo;
use App\Services\HierarchyService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8.9 (ED-01, P89-PERF-012): a user's subtree is read from the closure table once per request
 * or job, and a reporting-line change is visible at once — visibility is never stale.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->manager = Employee::factory()->create();
    $this->recruiter = Employee::factory()->create(['reports_to_id' => $this->manager->id]);
    $this->user = User::factory()->create(['employee_id' => $this->manager->id])->assignRole('manager');
});

function hierarchyMemoClosureQueries(Closure $run): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $run();
    $count = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'employee_hierarchy'))->count();
    DB::disableQueryLog();

    return $count;
}

test('repeated visibility checks in one request read the closure table once', function (): void {
    $hierarchy = app(HierarchyService::class);

    $queries = hierarchyMemoClosureQueries(function () use ($hierarchy): void {
        foreach (range(1, 25) as $i) {
            $hierarchy->visibleEmployeeIdsFor($this->user);
            $hierarchy->canView($this->user, $this->recruiter);
        }
    });

    expect($queries)->toBe(1)
        ->and($hierarchy->visibleEmployeeIdsFor($this->user)->sort()->values()->all())->toBe([$this->manager->id, $this->recruiter->id]);
});

test('a reporting-line change is visible immediately in the same request', function (): void {
    $hierarchy = app(HierarchyService::class);
    $otherManager = Employee::factory()->create();

    expect($hierarchy->canView($this->user, $this->recruiter))->toBeTrue();

    lifecycleFixture(fn () => $this->recruiter->update(['reports_to_id' => $otherManager->id]));
    $joiner = Employee::factory()->create(['reports_to_id' => $this->manager->id]);

    expect($hierarchy->canView($this->user, $this->recruiter))->toBeFalse()
        ->and($hierarchy->canView($this->user, $joiner))->toBeTrue();
});

test('each queued job starts with an empty memo', function (): void {
    $first = app(HierarchyMemo::class);
    app(HierarchyService::class)->visibleEmployeeIdsFor($this->user);

    // What the queue worker does before every job (QueueServiceProvider resetScope).
    app()->forgetScopedInstances();

    expect(app(HierarchyMemo::class))->not->toBe($first)
        ->and(hierarchyMemoClosureQueries(fn () => app(HierarchyService::class)->visibleEmployeeIdsFor($this->user)))->toBe(1);
});

test('a caller changing the returned ids cannot change what others see', function (): void {
    $hierarchy = app(HierarchyService::class);

    $hierarchy->visibleEmployeeIdsFor($this->user)->push(999999);

    expect($hierarchy->visibleEmployeeIdsFor($this->user)->contains(999999))->toBeFalse();
});
