<?php

namespace App\Services;

use App\Enums\AiToolCallStatus;
use App\Enums\AutomationExecutionStatus;
use App\Enums\CommunicationStatus;
use App\Models\AiToolCall;
use App\Models\AutomationExecution;
use App\Models\CandidateCommunication;
use App\Services\Communication\ProviderCircuitBreaker;
use App\Services\Tenancy\TenantContext;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 8.7 (D8.7-012/021/028): what the queue, the workers' output and the scheduler look like
 * right now — the Queue health page, the /health/queue endpoint and queue:health-check all read
 * this. Counts, ages, job class names and redacted exception text only: never a payload, a
 * message body, a recipient or a candidate's name.
 *
 * SaaS-1: two scopes. Inside a tenant (the Queue health page, per-tenant alerts) only jobs whose
 * payload declares that tenant are counted or listed, and only its stuck work. With no tenant
 * (the /health/queue endpoint, the platform pass of queue:health-check) it reports the platform:
 * counts across every tenant, never any tenant's records.
 */
class QueueHealthService
{
    /**
     * Every queue the shipped workers consume (docker-compose.yml).
     *
     * @var array<int, string>
     */
    public const array QUEUES = ['communications', 'security', 'billing', 'notifications', 'automation', 'documents', 'intelligence', 'integrations', 'exports', 'default'];

    public const int OLDEST_JOB_ALERT_MINUTES = 15;

    public const int STUCK_ALERT_MINUTES = 30;

    public const int HEARTBEAT_ALERT_MINUTES = 15;

    public const int WORKER_SILENT_MINUTES = 5;

    public function __construct(
        private readonly SchedulerHeartbeat $heartbeat,
        private readonly ProviderCircuitBreaker $circuit,
        private readonly WorkerHeartbeat $workers,
    ) {}

    /**
     * @return array<int, array{queue: string, depth: int, oldest_minutes: int|null}>
     */
    public function queues(): array
    {
        $rows = $this->jobRows('jobs')
            ->selectRaw('queue, count(*) as depth, min(available_at) as oldest')
            ->groupBy('queue')
            ->get()
            ->keyBy('queue');

        return collect([...self::QUEUES, ...$rows->keys()->diff(self::QUEUES)->all()])->map(fn (string $queue): array => [
            'queue' => $queue,
            'depth' => (int) ($rows[$queue]->depth ?? 0),
            'oldest_minutes' => isset($rows[$queue]) ? max(0, intdiv(now()->getTimestamp() - (int) $rows[$queue]->oldest, 60)) : null,
        ])->values()->all();
    }

    /**
     * Phase 8.10 (P810-OP-03): what a deploy drain must see before workers are stopped and the
     * schema changes — per queue, jobs ready to run, jobs delayed until later and jobs a worker
     * holds right now, plus scheduled tasks still holding their withoutOverlapping lock (a task
     * started with runInBackground outlives the scheduler process). Counts only; read-only.
     * Running tasks are null when the cache store is not the database (their locks live elsewhere).
     * The column aliases avoid MySQL reserved words (`delayed`), which SQLite accepts.
     *
     * @return array{queues: array<int, array{queue: string, ready: int, delayed: int, reserved: int}>, ready: int, delayed: int, reserved: int, running_tasks: int|null}
     */
    public function drainState(): array
    {
        $now = now()->getTimestamp();

        $rows = $this->jobRows('jobs')
            ->selectRaw(
                'queue,'
                .' sum(case when reserved_at is null and available_at <= ? then 1 else 0 end) as ready_jobs,'
                .' sum(case when reserved_at is null and available_at > ? then 1 else 0 end) as delayed_jobs,'
                .' sum(case when reserved_at is not null then 1 else 0 end) as reserved_jobs',
                [$now, $now],
            )
            ->groupBy('queue')
            ->get()
            ->keyBy('queue');

        $queues = collect([...self::QUEUES, ...$rows->keys()->diff(self::QUEUES)->all()])->map(fn (string $queue): array => [
            'queue' => $queue,
            'ready' => (int) ($rows[$queue]->ready_jobs ?? 0),
            'delayed' => (int) ($rows[$queue]->delayed_jobs ?? 0),
            'reserved' => (int) ($rows[$queue]->reserved_jobs ?? 0),
        ])->values();

        return [
            'queues' => $queues->all(),
            'ready' => $queues->sum('ready'),
            'delayed' => $queues->sum('delayed'),
            'reserved' => $queues->sum('reserved'),
            'running_tasks' => $this->runningScheduledTasks($now),
        ];
    }

    /**
     * Scheduled tasks whose withoutOverlapping lock is held and unexpired. onOneServer locks carry
     * an HHmm suffix and stay held for their minute after the run, so they are not counted.
     */
    private function runningScheduledTasks(int $now): ?int
    {
        $store = (string) config('cache.default');

        if (config("cache.stores.{$store}.driver") !== 'database') {
            return null;
        }

        return DB::connection(config("cache.stores.{$store}.lock_connection") ?: config("cache.stores.{$store}.connection"))
            ->table(config("cache.stores.{$store}.lock_table") ?: 'cache_locks')
            ->where('key', 'like', '%framework/schedule-%')
            ->where('expiration', '>', $now)
            ->pluck('key')
            ->filter(fn (string $key): bool => preg_match('#framework/schedule-[0-9a-f]{40}$#', $key) === 1)
            ->count();
    }

    /**
     * @return array{last_hour: int, total: int, recent: array<int, array{id: int, uuid: string, queue: string, job: string, failed_at: string, error: string}>}
     */
    public function failedJobs(int $limit = 20): array
    {
        return [
            'last_hour' => $this->jobRows('failed_jobs')->where('failed_at', '>=', now()->subHour())->count(),
            'total' => $this->jobRows('failed_jobs')->count(),
            'recent' => $this->jobRows('failed_jobs')->orderByDesc('failed_at')->limit($limit)->get(['id', 'uuid', 'queue', 'payload', 'exception', 'failed_at'])
                ->map(fn (object $row): array => [
                    'id' => (int) $row->id,
                    'uuid' => (string) $row->uuid,
                    'queue' => (string) $row->queue,
                    'job' => (string) (json_decode((string) $row->payload, true)['displayName'] ?? 'unknown'),
                    'failed_at' => (string) $row->failed_at,
                    // The stored text is already redacted (RedactingFailedJobProvider); only its
                    // first line is shown.
                    'error' => mb_substr(strtok((string) $row->exception, "\n") ?: '', 0, 300),
                ])->all(),
        ];
    }

    /**
     * Work left in a state it should have moved on from.
     *
     * @return array{messages_queued: int, messages_sending: int, automation_running: int, ai_actions_approved: int}
     */
    public function stuck(): array
    {
        $threshold = now()->subMinutes(self::STUCK_ALERT_MINUTES);

        // SaaS-1: the platform scope counts across tenants (numbers only); a tenant sees its own.
        $count = fn (EloquentBuilder $query): int => TenantContext::current()->hasTenant() ? $query->count() : $query->withoutTenancy()->count();

        return [
            'messages_queued' => $count(CandidateCommunication::query()->where('status', CommunicationStatus::Queued)->where('queued_at', '<', $threshold)),
            'messages_sending' => $count(CandidateCommunication::query()->where('status', CommunicationStatus::Sending)->where('updated_at', '<', $threshold)),
            'automation_running' => $count(AutomationExecution::query()->where('status', AutomationExecutionStatus::Running)->where('started_at', '<', $threshold)),
            'ai_actions_approved' => $count(AiToolCall::query()->where('status', AiToolCallStatus::Approved)->where('approved_at', '<', $threshold)),
        ];
    }

    /**
     * @return array<int, string>
     */
    /**
     * A failed job of the current scope by uuid (the Queue health page's Retry), or null.
     */
    public function failedJob(string $uuid): ?object
    {
        return $this->jobRows('failed_jobs')->where('uuid', $uuid)->first(['id', 'uuid', 'queue', 'payload']);
    }

    /**
     * SaaS-1: queue rows in scope — a tenant's own jobs (their payload's top-level tenant_id,
     * written by TenantQueueGuard), or every job for the platform.
     */
    private function jobRows(string $table): Builder
    {
        $tenantId = TenantContext::current()->id();

        return DB::table($table)->when($tenantId !== null, fn (Builder $query) => $query->where('payload->tenant_id', $tenantId));
    }

    public function pausedProviders(): array
    {
        return collect(config('communications.providers', []))
            ->values()
            ->unique()
            ->filter(fn (string $provider): bool => $this->circuit->isOpen($provider))
            ->values()
            ->all();
    }

    /**
     * Problems worth waking someone for (D8.7-028 thresholds), keyed so repeats can be deduplicated.
     *
     * @return array<string, string>
     */
    public function problems(): array
    {
        $problems = [];
        $failed = $this->failedJobs(0)['last_hour'];

        if ($failed > 0) {
            $problems['failed-jobs'] = "{$failed} job(s) failed in the last hour.";
        }

        foreach ($this->queues() as $queue) {
            if (($queue['oldest_minutes'] ?? 0) > self::OLDEST_JOB_ALERT_MINUTES) {
                $problems["queue-backlog:{$queue['queue']}"] = "The oldest job on the {$queue['queue']} queue has waited {$queue['oldest_minutes']} minutes — is its worker running?";
            }
        }

        $stuck = array_filter($this->stuck());

        if ($stuck !== []) {
            $problems['stuck-work'] = 'Work stuck for over '.self::STUCK_ALERT_MINUTES.' minutes: '.collect($stuck)->map(fn (int $count, string $kind) => str_replace('_', ' ', $kind).' '.$count)->implode(', ').'.';
        }

        $lastTick = $this->heartbeat->lastTick();
        $expectProcesses = (bool) config('queue.expect_processes');

        if ($lastTick !== null && $lastTick->lt(now()->subMinutes(self::HEARTBEAT_ALERT_MINUTES))) {
            $problems['scheduler-silent'] = "The scheduler has not run a task since {$lastTick->toDateTimeString()} UTC — is the scheduler container running?";
        } elseif ($lastTick === null && $expectProcesses) {
            // Phase 8.9 (P89-OPS-002): a scheduler that never reported is a problem too, where one runs.
            $problems['scheduler-silent'] = 'The scheduler has never reported a task — is the scheduler container running?';
        }

        if ($expectProcesses) {
            foreach ($this->workers->lastBeats() as $queues => $at) {
                if ($at === null || $at->lt(now()->subMinutes(self::WORKER_SILENT_MINUTES))) {
                    $problems["worker-silent:{$queues}"] = "The worker for [{$queues}] has not reported ".($at === null ? 'since it was deployed' : 'since '.$at->toDateTimeString().' UTC').' — is it running?';
                }
            }
        }

        foreach ($this->pausedProviders() as $provider) {
            $problems["provider-paused:{$provider}"] = "Messages through {$provider} are paused after repeated provider failures; they will be sent when it recovers.";
        }

        // SaaS-7 (C5): one task that keeps failing, or has not finished for three of its runs while
        // the scheduler itself is alive (a stale overlap lock, an exception every time).
        if (! isset($problems['scheduler-silent'])) {
            foreach ($this->heartbeat->tasks() as $task) {
                if ($task['outcome'] === 'failed') {
                    $problems["scheduled-task-failed:{$task['task']}"] = "The scheduled task {$task['task']} failed on its last run ({$task['at']} UTC).";
                } elseif ($task['at'] !== null && $this->missedRuns($task['expression'], $task['finished_at'])) {
                    $problems["scheduled-task-stale:{$task['task']}"] = "The scheduled task {$task['task']} has not finished for three of its runs (last finished: ".($task['finished_at'] ?? 'never').') — is it skipped by a stale lock?';
                }
            }
        }

        if ((int) config('queue.connections.database.retry_after') <= (int) config('queue.worker_max_timeout')) {
            $problems['retry-after'] = 'DB_QUEUE_RETRY_AFTER is not above the longest worker timeout — a running job can be handed to a second worker.';
        }

        return $problems;
    }

    /**
     * SaaS-1: the problems a tenant's own administrators can act on — its failed jobs, its queued
     * work waiting too long, its stuck work. Platform signals (workers, scheduler, providers,
     * configuration) are reported by the platform pass instead.
     *
     * @return array<string, string>
     */
    public function tenantProblems(): array
    {
        return array_filter(
            $this->problems(),
            fn (string $key): bool => $key === 'failed-jobs' || $key === 'stuck-work' || str_starts_with($key, 'queue-backlog:'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * SaaS-7 (C5): tenantProblems() of every tenant at once — one grouped pass over the queue
     * tables and the stuck-work tables, instead of one full scan of each per tenant every five
     * minutes. Same keys and messages; tenants without problems are absent. Platform scope only.
     *
     * @return array<int, array<string, string>>
     */
    public function problemsByTenant(): array
    {
        $problems = [];

        DB::table('failed_jobs')->where('failed_at', '>=', now()->subHour())->whereNotNull('payload->tenant_id')
            ->select('payload->tenant_id as tenant_id')->selectRaw('count(*) as failed')->groupBy('payload->tenant_id')->get()
            ->each(function (object $row) use (&$problems): void {
                $problems[(int) $row->tenant_id]['failed-jobs'] = "{$row->failed} job(s) failed in the last hour.";
            });

        DB::table('jobs')->where('available_at', '<', now()->subMinutes(self::OLDEST_JOB_ALERT_MINUTES)->getTimestamp())->whereNotNull('payload->tenant_id')
            ->select('queue', 'payload->tenant_id as tenant_id')->selectRaw('min(available_at) as oldest')->groupBy('queue', 'payload->tenant_id')->get()
            ->each(function (object $row) use (&$problems): void {
                $minutes = max(0, intdiv(now()->getTimestamp() - (int) $row->oldest, 60));
                $problems[(int) $row->tenant_id]["queue-backlog:{$row->queue}"] = "The oldest job on the {$row->queue} queue has waited {$minutes} minutes — is its worker running?";
            });

        $threshold = now()->subMinutes(self::STUCK_ALERT_MINUTES);
        $stuck = [];
        $count = function (string $kind, EloquentBuilder $query) use (&$stuck): void {
            $query->withoutTenancy()->toBase()->select('tenant_id')->selectRaw('count(*) as stuck')->groupBy('tenant_id')->get()
                ->each(function (object $row) use (&$stuck, $kind): void {
                    $stuck[(int) $row->tenant_id][$kind] = (int) $row->stuck;
                });
        };

        $count('messages_queued', CandidateCommunication::query()->where('status', CommunicationStatus::Queued)->where('queued_at', '<', $threshold));
        $count('messages_sending', CandidateCommunication::query()->where('status', CommunicationStatus::Sending)->where('updated_at', '<', $threshold));
        $count('automation_running', AutomationExecution::query()->where('status', AutomationExecutionStatus::Running)->where('started_at', '<', $threshold));
        $count('ai_actions_approved', AiToolCall::query()->where('status', AiToolCallStatus::Approved)->where('approved_at', '<', $threshold));

        foreach ($stuck as $tenantId => $kinds) {
            $ordered = array_filter(array_merge(array_fill_keys(['messages_queued', 'messages_sending', 'automation_running', 'ai_actions_approved'], 0), $kinds));
            $problems[$tenantId]['stuck-work'] = 'Work stuck for over '.self::STUCK_ALERT_MINUTES.' minutes: '.collect($ordered)->map(fn (int $n, string $kind) => str_replace('_', ' ', $kind).' '.$n)->implode(', ').'.';
        }

        ksort($problems);

        return $problems;
    }

    /**
     * Whether a task has not finished since three of its scheduled runs ago.
     */
    private function missedRuns(string $expression, ?string $finishedAt): bool
    {
        try {
            $thirdLastRun = Carbon::instance((new CronExpression($expression))->getPreviousRunDate(now(), 2));
        } catch (Throwable) {
            return false;
        }

        return $finishedAt === null || Carbon::parse($finishedAt)->lt($thirdLastRun);
    }

    /**
     * Everything above, for the page and the endpoint.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $problems = $this->problems();

        return [
            'status' => $problems === [] ? 'ok' : 'attention',
            'checked_at' => now()->toIso8601String(),
            'problems' => array_values($problems),
            'queues' => $this->queues(),
            'failed_jobs' => $this->failedJobs(),
            'stuck' => $this->stuck(),
            'paused_providers' => $this->pausedProviders(),
            'scheduler' => ['last_tick' => $this->heartbeat->lastTick()?->toIso8601String(), 'tasks' => $this->heartbeat->tasks()],
            'workers' => collect($this->workers->lastBeats())->map(fn (?Carbon $at, string $queues): array => ['queues' => $queues, 'last_beat' => $at?->toIso8601String()])->values()->all(),
        ];
    }
}
