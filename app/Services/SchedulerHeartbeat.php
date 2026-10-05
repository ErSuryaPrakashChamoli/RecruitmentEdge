<?php

namespace App\Services;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;

/**
 * Phase 8.7 (D8.7-021/028): the scheduler's pulse. Every scheduled task's outcome (finished,
 * failed, skipped because the previous run was still going) is recorded in the shared cache with
 * its time; the Queue health page lists them and queue:health-check alerts when the scheduler has
 * gone quiet. Nothing here runs a task.
 */
class SchedulerHeartbeat
{
    public const string LAST_TICK_KEY = 'scheduler:heartbeat:last-tick';

    public function record(Event $task, string $outcome): void
    {
        $now = now();
        // SaaS-7 (C5): when it last finished, kept across skips and failures, so a task that keeps
        // failing or keeps being skipped (a stale overlap lock) is noticed (QueueHealthService).
        $finishedAt = $outcome === 'finished' ? $now->toIso8601String() : (Cache::get($this->keyFor($task))['finished_at'] ?? null);

        Cache::forever($this->keyFor($task), ['outcome' => $outcome, 'at' => $now->toIso8601String(), 'finished_at' => $finishedAt, 'exit_code' => $task->exitCode]);
        Cache::forever(self::LAST_TICK_KEY, $now->toIso8601String());
    }

    public function lastTick(): ?Carbon
    {
        $at = Cache::get(self::LAST_TICK_KEY);

        return is_string($at) ? Carbon::parse($at) : null;
    }

    /**
     * Every scheduled task with its last recorded outcome.
     *
     * @return array<int, array{task: string, expression: string, outcome: string|null, at: string|null, finished_at: string|null}>
     */
    public function tasks(): array
    {
        return collect($this->schedule()->events())->map(function (Event $task): array {
            $last = Cache::get($this->keyFor($task));

            return [
                'task' => $this->nameOf($task),
                'expression' => $task->expression,
                'outcome' => $last['outcome'] ?? null,
                'at' => $last['at'] ?? null,
                'finished_at' => $last['finished_at'] ?? null,
            ];
        })->values()->all();
    }

    /**
     * The schedule is defined in routes/console.php, which only the console kernel loads — in a
     * web request (the Queue health page, /health/queue) it is loaded here, once, from the same file.
     */
    private function schedule(): Schedule
    {
        $schedule = app(Schedule::class);

        if ($schedule->events() === []) {
            app()->instance(Schedule::class, $schedule = new Schedule(config('app.schedule_timezone') ?? config('app.timezone')));
            ScheduleFacade::clearResolvedInstance(Schedule::class);
            require base_path('routes/console.php');
        }

        return $schedule;
    }

    public function nameOf(Event $task): string
    {
        // SaaS-1: a tenant task is scheduled as `tenants:dispatch <task>` / `tenants:run <task> --all`;
        // it is reported by the task it runs for every tenant.
        if (preg_match("/artisan['\"]?\\s+(?:tenants:(?:dispatch|run)\\s+)?([\\w:.-]+)/", (string) $task->command, $matches) === 1) {
            return $matches[1];
        }

        return $task->description ?? (string) $task->command;
    }

    private function keyFor(Event $task): string
    {
        return 'scheduler:heartbeat:'.sha1($task->mutexName());
    }
}
