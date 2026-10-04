<?php

namespace App\Console\Commands;

use App\Jobs\RunTenantScheduledTask;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantDirectory;
use App\Services\Tenancy\TenantTasks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

/**
 * SaaS-1: the scheduler's entry for a tenant task. It only enumerates the tenants whose status
 * allows background work and queues one RunTenantScheduledTask per tenant, dispatched from inside
 * that tenant — no business processing happens in the scheduler process.
 */
#[Signature('tenants:dispatch {task : A task listed in TenantTasks::QUEUED}')]
#[Description('Queue one run of a scheduled tenant task for every active tenant')]
class TenantsDispatch extends Command
{
    public function handle(TenantDirectory $tenants): int
    {
        $task = (string) $this->argument('task');

        if (! isset(TenantTasks::QUEUED[$task])) {
            $this->error("[{$task}] is not a queued tenant task.");

            return self::FAILURE;
        }

        $context = TenantContext::current();
        $queued = 0;

        foreach ($tenants->forBackgroundWork() as $tenant) {
            // Bus::dispatch queues at once, inside the tenant. (A PendingDispatch returned from the
            // callback would only be queued after run() had restored the previous context.)
            $context->run($tenant, fn () => Bus::dispatch(new RunTenantScheduledTask($task)));
            $queued++;
        }

        $this->info("{$task}: queued for {$queued} tenant(s).");

        return self::SUCCESS;
    }
}
