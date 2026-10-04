<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantDirectory;
use App\Services\Tenancy\TenantTasks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SaaS-1: runs a tenant task inside named tenants (operators: --tenant=slug, repeatable) or every
 * active tenant (--all: the scheduler's background tasks). One tenant at a time; each tenant is
 * isolated (its failure is reported and logged, the others still run) and logged with its id
 * and duration.
 */
#[Signature('tenants:run {task : A task listed in TenantTasks} {--tenant=* : Tenant slug(s)} {--all : Every tenant whose status allows background work}')]
#[Description('Run a scheduled tenant task inside one, several or all active tenants')]
class TenantsRun extends Command
{
    public function handle(TenantDirectory $directory): int
    {
        $task = (string) $this->argument('task');

        if (! TenantTasks::isTenantTask($task)) {
            $this->error("[{$task}] is not a tenant task.");

            return self::FAILURE;
        }

        $slugs = (array) $this->option('tenant');

        if (! $this->option('all') && $slugs === []) {
            $this->error('Name the tenant(s) with --tenant=<slug>, or pass --all.');

            return self::FAILURE;
        }

        $tenants = $this->option('all')
            ? $directory->forBackgroundWork()
            : Tenant::query()->whereIn('slug', $slugs)->get()->filter(fn (Tenant $tenant): bool => $tenant->allowsBackgroundWork())->values();

        $failures = 0;

        foreach ($tenants as $tenant) {
            $started = hrtime(true);

            try {
                TenantContext::current()->run($tenant, fn (): int => Artisan::call($task, [], $this->output));
                Log::info('tenancy.task_finished', ['task' => $task, 'tenant_id' => $tenant->id, 'duration_ms' => (int) round((hrtime(true) - $started) / 1e6)]);
            } catch (Throwable $exception) {
                $failures++;
                report($exception);
                Log::warning('tenancy.task_failed', ['task' => $task, 'tenant_id' => $tenant->id, 'exception' => $exception::class]);
                $this->error("{$task} failed for tenant {$tenant->slug}.");
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
