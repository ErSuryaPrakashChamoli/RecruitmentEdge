<?php

namespace App\Services\Entitlements;

use App\Enums\Entitlement;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * SaaS-3: a queued job for a capability the tenant's plan no longer includes is skipped — removed
 * from the queue without running and logged with its tenant — instead of failing or retrying
 * forever. (A tenant that is not usable at all is stopped earlier by the queue guard, and its work
 * waits for reactivation: PausedTenantWork.)
 */
final readonly class SkipWithoutEntitlement
{
    public function __construct(private Entitlement $entitlement) {}

    public function handle(object $job, Closure $next): mixed
    {
        if (! app(EntitlementService::class)->allows($this->entitlement)) {
            Log::notice('entitlement.job_skipped', ['job' => $job::class, 'entitlement' => $this->entitlement->value]);

            return null;
        }

        return $next($job);
    }
}
