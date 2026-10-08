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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SaaS-1: runs a tenant task inside named tenants (operators: --tenant=slug, repeatable) or every
 * active tenant (--all: the scheduler's background tasks). One tenant at a time; each tenant is
 * isolated (its failure is reported and logged, the others still run) and logged with its id
 * and duration.
 *
 * SaaS-7 (C5): with --all and --budget=<seconds>, no tenant is started once the budget is spent;
 * the pass resumes after the last tenant it finished on the next run (a rotating cursor), so
 * tenants with high ids are never starved and a long pass never outlives its overlap lock (the
 * schedule sizes budget + the longest single tenant below the lock's expiry).
 */
#[Signature('tenants:run {task : A task listed in TenantTasks} {--tenant=* : Tenant slug(s)} {--all : Every tenant whose status allows background work} {--budget= : With --all: seconds after which no further tenant is started (resumes there next run)} {--with=* : An option for the task: name or name=value (e.g. --with=execute)}')]
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

        $budget = $this->option('all') && filled($this->option('budget')) ? max(1, (int) $this->option('budget')) : null;
        $cursorKey = 'tenancy:run-cursor:'.$task;
        $startAfter = $budget !== null ? (int) Cache::get($cursorKey, 0) : 0;

        $tenants = $this->option('all')
            ? ($startAfter > 0 ? $directory->lazyForBackgroundWork(afterId: $startAfter)->concat($directory->lazyForBackgroundWork()->takeWhile(fn (Tenant $tenant): bool => $tenant->id <= $startAfter)) : $directory->lazyForBackgroundWork())
            : Tenant::query()->whereIn('slug', $slugs)->get()->filter(fn (Tenant $tenant): bool => $tenant->allowsBackgroundWork())->values();

        $failures = 0;
        $passStarted = now()->getTimestamp();
        $finished = 0;

        foreach ($tenants as $tenant) {
            if ($budget !== null && now()->getTimestamp() - $passStarted >= $budget) {
                Log::info('tenancy.task_budget_spent', ['task' => $task, 'tenants_finished' => $finished, 'resume_after_tenant_id' => (int) Cache::get($cursorKey, 0)]);
                $this->warn("{$task}: budget of {$budget}s spent after {$finished} tenant(s); the next run resumes there.");

                return $failures === 0 ? self::SUCCESS : self::FAILURE;
            }

            // SaaS-7: the pass read this tenant up to a chunk (and a budget) ago. Its work runs only if
            // the tenant still allows background work now — read with a shared lock, so a suspension
            // or closure in flight is waited for and then honoured (SaaS-7 race test).
            $current = Tenant::query()->whereKey($tenant->id)->sharedLock()->first();

            if ($current === null || ! $current->allowsBackgroundWork()) {
                Log::info('tenancy.task_skipped', ['task' => $task, 'tenant_id' => $tenant->id, 'reason' => 'the tenant no longer allows background work']);

                continue;
            }

            $started = hrtime(true);

            try {
                TenantContext::current()->run($current, fn (): int => Artisan::call($task, $this->taskOptions(), $this->output));
                Log::info('tenancy.task_finished', ['task' => $task, 'tenant_id' => $tenant->id, 'duration_ms' => (int) round((hrtime(true) - $started) / 1e6)]);
            } catch (Throwable $exception) {
                $failures++;
                report($exception);
                Log::warning('tenancy.task_failed', ['task' => $task, 'tenant_id' => $tenant->id, 'exception' => $exception::class]);
                $this->error("{$task} failed for tenant {$tenant->slug}.");
            }

            $finished++;

            if ($budget !== null) {
                Cache::forever($cursorKey, (int) $tenant->id);
            }
        }

        // A complete pass starts from the beginning next time.
        if ($budget !== null) {
            Cache::forget($cursorKey);
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, string|true>
     */
    private function taskOptions(): array
    {
        $options = [];

        foreach ((array) $this->option('with') as $option) {
            [$name, $value] = array_pad(explode('=', (string) $option, 2), 2, null);
            $options['--'.ltrim($name, '-')] = $value ?? true;
        }

        return $options;
    }
}
