<?php

namespace App\Services\Platform\Commercial;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantUnavailable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SaaS-3 (closes S1-05): queued work of a tenant that is not usable is refused by the queue guard
 * and waits in failed_jobs (TenantUnavailable) instead of running. When a Suspended tenant becomes
 * usable again (reactivated, trial extended or converted), that waiting work is queued again —
 * deterministically: exactly the tenant's jobs that failed for that reason, once, audited.
 */
class PausedTenantWork
{
    public function resumeAfterCommit(Tenant $tenant): void
    {
        DB::afterCommit(fn () => $this->resume($tenant));
    }

    /**
     * @return int how many jobs were queued again
     */
    public function resume(Tenant $tenant): int
    {
        $ids = [];

        DB::table('failed_jobs')->orderBy('id')->chunkById(500, function ($rows) use ($tenant, &$ids): void {
            foreach ($rows as $row) {
                $declared = json_decode((string) $row->payload, true)['tenant_id'] ?? null;

                if ($declared !== null && (int) $declared === (int) $tenant->getKey() && str_contains((string) $row->exception, class_basename(TenantUnavailable::class))) {
                    $ids[] = $row->uuid;
                }
            }
        });

        if ($ids !== []) {
            Artisan::call('queue:retry', ['id' => $ids]);
        }

        TenantContext::current()->run($tenant, fn () => AuditLog::record($tenant, 'tenant_work_resumed', null, ['jobs' => count($ids)]));
        Log::notice('platform.tenant_work_resumed', ['tenant_id' => $tenant->getKey(), 'jobs' => count($ids)]);

        return count($ids);
    }
}
