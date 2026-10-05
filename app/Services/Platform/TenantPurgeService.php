<?php

namespace App\Services\Platform;

use App\Console\Commands\StorageAudit;
use App\Enums\DeletionRequestStatus;
use App\Enums\PlatformEventSeverity;
use App\Enums\TenantStatus;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantDeletionRequest;
use App\Services\Platform\Commercial\TenantLifecycleService;
use App\Services\Tenancy\TenantCache;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * SaaS-5: purges one approved, due tenant deletion — idempotent, resumable, observable, one worker
 * at a time.
 *
 * 1. Claim, under the tenant row lock then the request row: only an approved request past its grace
 *    period (the tenant deletion-pending), a failed one, or a purge whose worker's lease expired.
 *    A second worker finds a live lease and does nothing — concurrent purges collapse into one.
 * 2. The tenant's queued and failed jobs are dropped (they would fail at the queue guard anyway).
 * 3. Its files: the ones its rows still name outside the tenant prefix (pre-SaaS-1 paths, S1-07),
 *    then everything under tenants/{id}/ on every disk; then its cache entries (t:{id}:…).
 * 4. Its rows, table by table in TenantPurgePlan order, in chunks, every statement keyed by the
 *    request's tenant id; progress is recorded after every table, and the lease is renewed (a lost
 *    lease stops this worker).
 * 5. Done: the request is Purged and the tenant Deleted (SaaS-3) — its row, the retained tables and
 *    the platform's records stay.
 *
 * A failure is recorded (Failed, the error, a critical platform event) — never presented as done —
 * and the next run resumes where it stopped: deleting what is already deleted is a no-op.
 */
class TenantPurgeService
{
    /**
     * Failed runs before a purge waits for an operator ("Purge now" resets the budget). Runs that
     * resumed after a worker's lease expired are not failures.
     */
    public const int MAX_FAILURES = 5;

    public function __construct(
        private readonly TenantLifecycleService $lifecycle,
        private readonly PlatformEvents $events,
    ) {}

    public function purge(int $requestId, ?string $worker = null): string
    {
        $worker ??= (string) Str::uuid();
        $claim = $this->claim($requestId, $worker);

        if ($claim !== 'claimed') {
            return $claim;
        }

        /** @var TenantDeletionRequest $request */
        $request = TenantDeletionRequest::query()->findOrFail($requestId);
        $tenantId = (int) $request->tenant_id;

        try {
            // The plan is validated before anything is deleted: a refused plan deletes nothing.
            $plan = TenantPurgePlan::build();
            $progress = (array) ($request->progress ?? []);

            if (($progress['queue'] ?? null) !== 'done') {
                $progress['queue_jobs_removed'] = $this->dropQueuedWork($tenantId);
                $progress['queue'] = 'done';
                $this->saveProgress($requestId, $worker, $progress);
            }

            if (($progress['files'] ?? null) !== 'done') {
                $progress['files_removed'] = $this->removeFiles($tenantId, $plan->tables);
                $progress['files'] = 'done';
                $this->saveProgress($requestId, $worker, $progress);
            }

            if (($progress['cache'] ?? null) !== 'done') {
                $progress['cache_entries_removed'] = $this->forgetCache($tenantId);
                $progress['cache'] = 'done';
                $this->saveProgress($requestId, $worker, $progress);
            }

            $progress['retained'] = $plan->retained;

            foreach ($plan->nullFirst as $table => $columns) {
                if (($progress['nulled'][$table] ?? false) !== true) {
                    DB::table($table)->where('tenant_id', $tenantId)->update(array_fill_keys($columns, null));
                    $progress['nulled'][$table] = true;
                }
            }

            foreach ($plan->tables as $table) {
                if (($progress['tables'][$table]['done'] ?? false) === true) {
                    continue;
                }

                $deleted = (int) ($progress['tables'][$table]['rows'] ?? 0);

                do {
                    $batch = DB::table($table)->where('tenant_id', $tenantId)->limit((int) config('platform.deletion.chunk', 1000))->delete();
                    $deleted += $batch;
                } while ($batch > 0);

                $progress['tables'][$table] = ['rows' => $deleted, 'done' => true];
                $this->saveProgress($requestId, $worker, $progress);
            }

            return $this->finish($requestId, $worker);
        } catch (LeaseLost) {
            return 'lease lost';
        } catch (Throwable $e) {
            $this->fail($requestId, $worker, $e);

            return 'failed';
        }
    }

    private function claim(int $requestId, string $worker): string
    {
        $request = TenantDeletionRequest::query()->find($requestId);

        if ($request === null) {
            return 'missing';
        }

        return DB::transaction(function () use ($request, $worker): string {
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->whereKey($request->tenant_id)->lockForUpdate()->firstOrFail();
            /** @var TenantDeletionRequest $locked */
            $locked = TenantDeletionRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            $status = $locked->status;

            if (in_array($status, [DeletionRequestStatus::Requested, DeletionRequestStatus::Cancelled, DeletionRequestStatus::Purged], true)) {
                return "nothing to purge ({$status->value})";
            }

            if ($status === DeletionRequestStatus::Approved && ($locked->purge_after === null || $locked->purge_after->isFuture())) {
                return 'not due';
            }

            if ($status === DeletionRequestStatus::Purging && $locked->lease_until !== null && $locked->lease_until->isFuture() && $locked->lease_owner !== $worker) {
                return 'already running';
            }

            if ($status === DeletionRequestStatus::Failed && $locked->failures >= self::MAX_FAILURES) {
                return 'failures exhausted';
            }

            if ($tenant->status !== TenantStatus::DeletionPending) {
                return "tenant is {$tenant->status->value}";
            }

            $resumed = $status !== DeletionRequestStatus::Approved;
            $locked->forceFill([
                'status' => DeletionRequestStatus::Purging,
                'purge_started_at' => $locked->purge_started_at ?? now(),
                'lease_owner' => $worker,
                'lease_until' => now()->addMinutes((int) config('platform.deletion.lease_minutes', 15)),
                'attempts' => $locked->attempts + 1,
            ])->save();

            TenantContext::current()->run($tenant, fn () => AuditLog::record($locked, $resumed ? 'tenant_purge_resumed' : 'tenant_purge_started', ['status' => $status->value], ['status' => DeletionRequestStatus::Purging->value, 'attempt' => $locked->attempts]));
            $this->events->record($resumed ? 'purge.resumed' : 'purge.started', PlatformEventSeverity::Critical, ($resumed ? 'Purge resumed: ' : 'Purge started: ').$tenant->slug, $tenant, ['request_id' => $locked->id, 'attempt' => $locked->attempts], "purge.started:{$locked->id}:{$locked->attempts}");

            return 'claimed';
        });
    }

    /**
     * @param  array<string, mixed>  $progress
     */
    private function saveProgress(int $requestId, string $worker, array $progress): void
    {
        $renewed = TenantDeletionRequest::query()->whereKey($requestId)->where('status', DeletionRequestStatus::Purging->value)->where('lease_owner', $worker)
            ->update(['progress' => json_encode($progress), 'lease_until' => now()->addMinutes((int) config('platform.deletion.lease_minutes', 15)), 'updated_at' => now()]);

        if ($renewed !== 1) {
            throw new LeaseLost;
        }
    }

    private function finish(int $requestId, string $worker): string
    {
        $request = TenantDeletionRequest::query()->findOrFail($requestId);

        DB::transaction(function () use ($request, $worker): void {
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->whereKey($request->tenant_id)->lockForUpdate()->firstOrFail();
            /** @var TenantDeletionRequest $locked */
            $locked = TenantDeletionRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== DeletionRequestStatus::Purging || $locked->lease_owner !== $worker) {
                throw new LeaseLost;
            }

            $locked->forceFill(['status' => DeletionRequestStatus::Purged, 'is_open' => null, 'purge_completed_at' => now(), 'lease_owner' => null, 'lease_until' => null, 'last_error' => null])->save();

            if ($tenant->status === TenantStatus::DeletionPending) {
                $this->lifecycle->markDeleted($tenant, "Purged (deletion request #{$locked->id})");
            }

            TenantContext::current()->run($tenant, fn () => AuditLog::record($locked, 'tenant_purge_completed', ['status' => DeletionRequestStatus::Purging->value], ['status' => DeletionRequestStatus::Purged->value, 'tables' => count((array) ($locked->progress['tables'] ?? [])), 'files_removed' => $locked->progress['files_removed'] ?? 0]));
        });

        $this->events->record('purge.completed', PlatformEventSeverity::Critical, 'Purge completed: '.Tenant::query()->whereKey($request->tenant_id)->value('slug'), Tenant::query()->find($request->tenant_id), ['request_id' => $requestId], "purge.completed:{$requestId}");

        return 'purged';
    }

    private function fail(int $requestId, string $worker, Throwable $e): void
    {
        $error = mb_substr($e::class.': '.$e->getMessage(), 0, 255);

        TenantDeletionRequest::query()->whereKey($requestId)->where('lease_owner', $worker)
            ->update(['status' => DeletionRequestStatus::Failed->value, 'failures' => DB::raw('failures + 1'), 'lease_owner' => null, 'lease_until' => null, 'last_error' => $error, 'updated_at' => now()]);

        $request = TenantDeletionRequest::query()->find($requestId);
        $tenant = $request !== null ? Tenant::query()->find($request->tenant_id) : null;

        if ($request !== null && $tenant !== null) {
            TenantContext::current()->run($tenant, fn () => AuditLog::record($request, 'tenant_purge_failed', null, ['error' => $e::class, 'attempt' => $request->attempts]));
        }

        Log::error('platform.purge_failed', ['request_id' => $requestId, 'exception' => $e::class]);
        $this->events->record('purge.failed', PlatformEventSeverity::Critical, 'Purge failed: '.($tenant?->slug ?? "request #{$requestId}"), $tenant, ['request_id' => $requestId, 'error' => $e::class, 'attempt' => $request?->attempts], "purge.failed:{$requestId}:{$request?->attempts}");
    }

    /**
     * The tenant's waiting and failed jobs (their payloads name the tenant).
     */
    private function dropQueuedWork(int $tenantId): int
    {
        $removed = 0;

        foreach (['jobs', 'failed_jobs'] as $table) {
            $removed += DB::table($table)->where(fn ($query) => $query->where('payload', 'like', '%"tenant_id":'.$tenantId.',%')->orWhere('payload', 'like', '%"tenant_id":'.$tenantId.'}%'))->delete();
        }

        return $removed;
    }

    /**
     * The tenant's cache entries and locks (t:{id}:…, TenantCache). The database store — the
     * default — is cleared now; entries in other stores expire with their TTL (and are keyed by a
     * tenant that can no longer be entered).
     */
    private function forgetCache(int $tenantId): int
    {
        $config = (array) config('cache.stores.'.config('cache.default'), []);

        if (($config['driver'] ?? null) !== 'database') {
            return 0;
        }

        $pattern = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) config('cache.prefix').TenantCache::key('', $tenantId)).'%';
        $connection = DB::connection($config['connection'] ?? null);
        $removed = 0;

        foreach ([$config['table'] ?? 'cache', $config['lock_table'] ?? 'cache_locks'] as $table) {
            $removed += $connection->table($table)->whereRaw($connection->getQueryGrammar()->wrap('key')." like ? escape '!'", [$pattern])->delete();
        }

        return $removed;
    }

    /**
     * The tenant's files: those its rows name outside its prefix (legacy paths), then its prefix.
     */
    /**
     * @param  list<string>  $purgedTables
     */
    private function removeFiles(int $tenantId, array $purgedTables): int
    {
        $prefix = TenantStorage::ROOT.'/'.$tenantId.'/';
        $removed = 0;

        foreach (StorageAudit::REFERENCES as $reference) {
            if (! in_array($reference['table'], $purgedTables, true)) {
                continue;
            }

            DB::table($reference['table'])->where('tenant_id', $tenantId)->whereNotNull($reference['column'])->orderBy('id')
                ->chunkById(500, function ($rows) use ($reference, $prefix, &$removed): void {
                    foreach ($rows as $row) {
                        $path = (string) $row->{$reference['column']};
                        $disk = isset($reference['disk_column']) && filled($row->{$reference['disk_column']} ?? null) ? (string) $row->{$reference['disk_column']} : $reference['disk'];

                        if ($path === '' || str_starts_with($path, $prefix)) {
                            continue;
                        }

                        foreach (array_unique(array_filter([$disk, $reference['fallback_disk'] ?? null])) as $candidate) {
                            if (Storage::disk($candidate)->exists($path)) {
                                Storage::disk($candidate)->delete($path);
                                $removed++;
                            }
                        }
                    }
                }, 'id', 'id');
        }

        // Pre-SaaS-1 Filament exports of this tenant's export rows (filament_exports/{id}/, S1-07).
        DB::table('exports')->where('tenant_id', $tenantId)->select(['id', 'file_disk'])->lazyById(500)
            ->each(function (object $export) use (&$removed): void {
                $storage = Storage::disk(filled($export->file_disk) ? (string) $export->file_disk : 'local');
                $directory = 'filament_exports/'.$export->id;

                if ($storage->directoryExists($directory)) {
                    $removed += count($storage->allFiles($directory));
                    $storage->deleteDirectory($directory);
                }
            });

        foreach (['local', 'public'] as $disk) {
            $directory = rtrim($prefix, '/');
            $removed += count(Storage::disk($disk)->allFiles($directory));
            Storage::disk($disk)->deleteDirectory($directory);
        }

        return $removed;
    }
}
