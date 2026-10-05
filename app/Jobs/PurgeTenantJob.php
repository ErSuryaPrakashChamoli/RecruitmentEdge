<?php

namespace App\Jobs;

use App\Services\Platform\TenantPurgeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SaaS-5: runs one tenant purge (TenantPurgeService). A platform job — queued with no tenant, it
 * names the deletion request only. Idempotent: a duplicate job finds the purge done or running and
 * does nothing; a failed purge is recorded and resumed by the platform sweep, not by queue retries.
 */
class PurgeTenantJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Within the background worker's limit; a longer purge is resumed (lease) by the next sweep.
     */
    public int $timeout = 290;

    public function __construct(public readonly int $requestId)
    {
        $this->onQueue('integrations');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60];
    }

    public function handle(TenantPurgeService $purge): void
    {
        $purge->purge($this->requestId);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('platform.purge_job_failed', ['request_id' => $this->requestId, 'exception' => $exception !== null ? $exception::class : null]);
    }
}
