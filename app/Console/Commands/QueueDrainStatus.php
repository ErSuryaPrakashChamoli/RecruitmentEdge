<?php

namespace App\Console\Commands;

use App\Services\QueueHealthService;
use App\Services\SchedulerHeartbeat;
use App\Services\Tenancy\TenantContext;
use App\Services\WorkerHeartbeat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

/**
 * Phase 8.10 (P810-OP-03): the deploy drain check (docs/runbooks/queue-operations.md §1). Exit 0
 * once no job is ready to run or held by a worker and no scheduled task still holds its overlap
 * lock — the point at which the workers can be stopped without abandoning work. Delayed jobs are
 * reported but do not block: they stay queued and run after the release. With --wait it keeps
 * checking (every 5 s) while the workers empty the queues. Reads only; never writes or logs.
 */
#[Signature('queue:drain-status {--wait=0 : Keep checking for up to this many seconds until drained}')]
#[Description('Exit 0 when no job is ready or reserved and no scheduled task is running (deploy drain check)')]
class QueueDrainStatus extends Command
{
    public const int POLL_SECONDS = 5;

    public function handle(QueueHealthService $health, WorkerHeartbeat $workers, SchedulerHeartbeat $scheduler): int
    {
        $deadline = now()->addSeconds(max(0, (int) $this->option('wait')));
        // SaaS-1: a release drains the platform — every tenant's jobs count.
        $platform = fn (): array => TenantContext::current()->runWithoutTenant(fn (): array => $health->drainState());
        $state = $platform();

        while (! $this->drained($state) && now()->lt($deadline)) {
            Sleep::for(self::POLL_SECONDS)->seconds();
            $state = $platform();
        }

        $this->table(
            ['Queue', 'Ready', 'Delayed', 'Reserved'],
            collect($state['queues'])->map(fn (array $row): array => [$row['queue'], $row['ready'], $row['delayed'], $row['reserved']])->all(),
        );

        $this->line('Scheduled tasks running: '.($state['running_tasks'] ?? 'unknown (the cache store is not the database)'));

        foreach ($workers->lastBeats() as $queues => $at) {
            $this->line("Worker [{$queues}] last heartbeat: ".($at?->toIso8601String() ?? 'never'));
        }

        $this->line('Scheduler last tick: '.($scheduler->lastTick()?->toIso8601String() ?? 'never'));

        if ($state['delayed'] > 0) {
            $this->line("{$state['delayed']} delayed job(s) stay queued and run after the release.");
        }

        if (! $this->drained($state)) {
            $this->error("Not drained: {$state['ready']} ready, {$state['reserved']} reserved, ".($state['running_tasks'] ?? 0).' scheduled task(s) running.');

            return self::FAILURE;
        }

        $this->info('Drained: no job is ready or reserved and no scheduled task is running.');

        return self::SUCCESS;
    }

    /**
     * @param  array{ready: int, reserved: int, running_tasks: int|null}  $state
     */
    private function drained(array $state): bool
    {
        return $state['ready'] === 0 && $state['reserved'] === 0 && ($state['running_tasks'] ?? 0) === 0;
    }
}
