<?php

namespace App\Console\Commands;

use App\Services\QueueHealthService;
use App\Services\SchedulerHeartbeat;
use App\Services\WorkerHeartbeat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Phase 8.9 (P89-OPS-005/007): the container health check of a worker or the scheduler — exit 0 while
 * its heartbeat is fresh, 1 once it has gone quiet (crashed, hung or never started). Compose starts
 * the scheduler only once every worker is healthy, and reports an unhealthy container to whatever
 * monitors it; Compose itself restarts a process that exits, not one that is merely unhealthy.
 * Reads the heartbeat only; never writes and never logs.
 */
#[Signature('ops:heartbeat {component : worker or scheduler} {--queues= : The worker\'s --queue list} {--max-minutes= : How old the last beat may be}')]
#[Description('Exit 0 when a worker or the scheduler has reported recently (container health check)')]
class HeartbeatCheck extends Command
{
    public function handle(SchedulerHeartbeat $scheduler): int
    {
        [$last, $limit] = match ($this->argument('component')) {
            'worker' => [$this->workerBeat((string) $this->option('queues')), (int) ($this->option('max-minutes') ?: 3)],
            'scheduler' => [$scheduler->lastTick(), (int) ($this->option('max-minutes') ?: QueueHealthService::HEARTBEAT_ALERT_MINUTES)],
            default => [null, 0],
        };

        if ($last === null || $last->lt(now()->subMinutes($limit))) {
            $this->error('No heartbeat within '.$limit.' minute(s)'.($last !== null ? ' (last '.$last->toIso8601String().')' : '').'.');

            return self::FAILURE;
        }

        $this->line('Heartbeat '.$last->toIso8601String());

        return self::SUCCESS;
    }

    private function workerBeat(string $queues): ?Carbon
    {
        $at = Cache::get(WorkerHeartbeat::KEY_PREFIX.$queues);

        return is_string($at) ? Carbon::parse($at) : null;
    }
}
