<?php

namespace App\Jobs;

use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantTasks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * SaaS-1: one tenant's run of a scheduled task (TenantTasks::QUEUED), queued by tenants:dispatch
 * from inside that tenant — the tenant travels in the payload and TenantQueueGuard restores and
 * re-checks it before this runs, so the task's queries can only see that tenant.
 *
 * One attempt: the scheduler queues the next run on its own cadence, so a failed run is visible
 * in failed_jobs (with its tenant) rather than retried into the next one. Unique per task and
 * tenant, so a slow run is never doubled by the next dispatch.
 */
class RunTenantScheduledTask implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public array $backoff = [60];

    /**
     * Below the database queue's retry_after (330 s), so a running task is never handed to a
     * second worker — and (SaaS-7, S7-08) below its own worker's --timeout: set per queue in the
     * constructor (TenantTasks::timeoutFor).
     */
    public int $timeout = 110;

    public int $uniqueFor = 3600;

    public readonly int $tenantId;

    public function __construct(public readonly string $task)
    {
        $this->tenantId = TenantContext::current()->requireId();
        $this->onQueue(TenantTasks::QUEUED[$task] ?? 'default');
        $this->timeout = TenantTasks::timeoutFor((string) $this->queue);
    }

    public function uniqueId(): string
    {
        return "{$this->task}:{$this->tenantId}";
    }

    public function handle(): void
    {
        // Only allow-listed tenant tasks ever run (TenantTasks), and only in the job's own tenant.
        if (! isset(TenantTasks::QUEUED[$this->task])) {
            throw new InvalidArgumentException("[{$this->task}] is not a queued tenant task.");
        }

        if (TenantContext::current()->id() !== $this->tenantId) {
            throw new InvalidArgumentException('The scheduled task is not running in the tenant it was queued for.');
        }

        Artisan::call($this->task);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('tenancy.scheduled_task_failed', ['task' => $this->task, 'tenant_id' => $this->tenantId, 'exception' => $exception !== null ? $exception::class : null]);
    }
}
