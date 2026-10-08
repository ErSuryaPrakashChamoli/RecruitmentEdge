<?php

namespace App\Console\Commands;

use App\Jobs\RunTenantScheduledTask;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantDirectory;
use App\Services\Tenancy\TenantTasks;
use App\Services\Tenancy\TenantWorkProbes;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * SaaS-1: the scheduler's entry for a tenant task. It only enumerates the tenants whose status
 * allows background work and queues one RunTenantScheduledTask per tenant, dispatched from inside
 * that tenant — no business processing happens in the scheduler process.
 *
 * SaaS-7 (C5): for the frequent tasks, only tenants that hold something the task could act on
 * (TenantWorkProbes — a superset, ids only); tenants are enumerated lazily. --all-tenants skips
 * the probe (a full pass, e.g. after a data repair).
 */
#[Signature('tenants:dispatch {task : A task listed in TenantTasks::QUEUED} {--all-tenants : Queue for every active tenant, without the work probe}')]
#[Description('Queue one run of a scheduled tenant task for every active tenant')]
class TenantsDispatch extends Command
{
    public function handle(TenantDirectory $tenants, TenantWorkProbes $probes): int
    {
        $task = (string) $this->argument('task');

        if (! isset(TenantTasks::QUEUED[$task])) {
            $this->error("[{$task}] is not a queued tenant task.");

            return self::FAILURE;
        }

        $context = TenantContext::current();
        $queued = 0;

        $only = $this->option('all-tenants') ? null : $context->runWithoutTenant(fn (): ?array => $probes->tenantsFor($task));

        foreach ($tenants->lazyForBackgroundWork($only) as $tenant) {
            // Queued inside the tenant, through a PendingDispatch released within the callback (not
            // returned from it, which would queue it after run() restored the previous context).
            // SaaS-7: the PendingDispatch is what takes the job's unique lock — Bus::dispatch skips
            // it, so a run still queued or running was doubled on every tick of a backed-up queue.
            $context->run($tenant, function () use ($task): void {
                RunTenantScheduledTask::dispatch($task);
            });
            $queued++;
        }

        $this->info("{$task}: queued for {$queued} tenant(s)".($only !== null ? ' with work to do' : '').'; a run already queued or running is not queued again.');

        return self::SUCCESS;
    }
}
