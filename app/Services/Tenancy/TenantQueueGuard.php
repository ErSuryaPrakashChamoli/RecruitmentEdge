<?php

namespace App\Services\Tenancy;

use Illuminate\Contracts\Queue\Job;

/**
 * SaaS-1: every queued payload (jobs, queued listeners, notifications, mail, Filament exports and
 * imports) carries its tenant twice — inside Laravel's Context, which the worker restores before
 * the job runs, and as a top-level `tenant_id` for operators. Before a job runs this checks they
 * agree and that the tenant may still have background work done. A job queued with no tenant is
 * platform work: it runs with no tenant, so it cannot touch tenant-owned data.
 */
class TenantQueueGuard
{
    /**
     * @return array{tenant_id: int|null}
     */
    public function payload(): array
    {
        return ['tenant_id' => TenantContext::current()->id()];
    }

    public function check(Job $job): void
    {
        $context = TenantContext::current();
        $declared = $job->payload()['tenant_id'] ?? null;

        // The worker restored Context (and so the tenant) for this job; links it generates must
        // follow it, not whatever the previous job left in the shared URL generator.
        $context->syncUrlDefaults();

        if ($declared === null) {
            if ($context->hasTenant()) {
                throw new TenantUnavailable('A queued job without a declared tenant carried a tenant context.');
            }

            return;
        }

        if ($context->id() !== (int) $declared) {
            throw TenantUnavailable::forTenant((int) $declared, 'the job context does not match its payload');
        }

        $tenant = $context->tenant();

        if ($tenant === null) {
            throw TenantUnavailable::forTenant((int) $declared, 'it does not exist');
        }

        if (! $tenant->allowsBackgroundWork()) {
            throw TenantUnavailable::forTenant((int) $declared, 'its status is '.$tenant->status->value);
        }
    }
}
