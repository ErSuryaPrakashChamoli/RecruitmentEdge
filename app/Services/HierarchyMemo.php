<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Collection;

/**
 * Phase 8.9 (ED-01, P89-PERF-012): remembers each employee's subtree (closure-table descendants)
 * for the current request or queued job, so the dozens of visibility checks a page, a metric or a
 * job makes cost one closure-table query per employee instead of one each.
 *
 * Bound as a container *scoped* instance: a fresh, empty memo for every HTTP request, and the queue
 * worker forgets scoped instances before each job. HierarchyService resolves it on every call, so a
 * long-lived object holding a HierarchyService never carries a memo across jobs. A hierarchy change
 * (EmployeeObserver) flushes it at once, and entries expire after a minute as a safety net for
 * long-running commands. Only the subtree is remembered — permission checks are never cached here.
 */
final class HierarchyMemo
{
    private const int TTL_SECONDS = 60;

    /**
     * @var array<int, array{ids: list<int>, at: float}>
     */
    private array $descendants = [];

    /**
     * @param  Closure(): Collection<int, int>  $resolve
     * @return Collection<int, int> a fresh collection the caller may change
     */
    public function descendants(int $employeeId, Closure $resolve): Collection
    {
        $entry = $this->descendants[$employeeId] ?? null;

        if ($entry === null || microtime(true) - $entry['at'] > self::TTL_SECONDS) {
            $entry = ['ids' => $resolve()->map(fn ($id): int => (int) $id)->values()->all(), 'at' => microtime(true)];
            $this->descendants[$employeeId] = $entry;
        }

        return collect($entry['ids']);
    }

    public function flush(): void
    {
        $this->descendants = [];
    }
}
