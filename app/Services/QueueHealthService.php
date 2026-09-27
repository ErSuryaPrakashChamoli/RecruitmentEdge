<?php

namespace App\Services;

use App\Enums\AiToolCallStatus;
use App\Enums\AutomationExecutionStatus;
use App\Enums\CommunicationStatus;
use App\Models\AiToolCall;
use App\Models\AutomationExecution;
use App\Models\CandidateCommunication;
use App\Services\Communication\ProviderCircuitBreaker;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8.7 (D8.7-012/021/028): what the queue, the workers' output and the scheduler look like
 * right now — the Queue health page, the /health/queue endpoint and queue:health-check all read
 * this. Counts, ages, job class names and redacted exception text only: never a payload, a
 * message body, a recipient or a candidate's name.
 */
class QueueHealthService
{
    /**
     * Every queue the shipped workers consume (docker-compose.yml).
     *
     * @var array<int, string>
     */
    public const array QUEUES = ['communications', 'notifications', 'automation', 'intelligence', 'integrations', 'default'];

    public const int OLDEST_JOB_ALERT_MINUTES = 15;

    public const int STUCK_ALERT_MINUTES = 30;

    public const int HEARTBEAT_ALERT_MINUTES = 15;

    public function __construct(
        private readonly SchedulerHeartbeat $heartbeat,
        private readonly ProviderCircuitBreaker $circuit,
    ) {}

    /**
     * @return array<int, array{queue: string, depth: int, oldest_minutes: int|null}>
     */
    public function queues(): array
    {
        $rows = DB::table('jobs')
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
     * @return array{last_hour: int, total: int, recent: array<int, array{id: int, uuid: string, queue: string, job: string, failed_at: string, error: string}>}
     */
    public function failedJobs(int $limit = 20): array
    {
        return [
            'last_hour' => DB::table('failed_jobs')->where('failed_at', '>=', now()->subHour())->count(),
            'total' => DB::table('failed_jobs')->count(),
            'recent' => DB::table('failed_jobs')->orderByDesc('failed_at')->limit($limit)->get(['id', 'uuid', 'queue', 'payload', 'exception', 'failed_at'])
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

        return [
            'messages_queued' => CandidateCommunication::query()->where('status', CommunicationStatus::Queued)->where('queued_at', '<', $threshold)->count(),
            'messages_sending' => CandidateCommunication::query()->where('status', CommunicationStatus::Sending)->where('updated_at', '<', $threshold)->count(),
            'automation_running' => AutomationExecution::query()->where('status', AutomationExecutionStatus::Running)->where('started_at', '<', $threshold)->count(),
            'ai_actions_approved' => AiToolCall::query()->where('status', AiToolCallStatus::Approved)->where('approved_at', '<', $threshold)->count(),
        ];
    }

    /**
     * @return array<int, string>
     */
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

        if ($lastTick !== null && $lastTick->lt(now()->subMinutes(self::HEARTBEAT_ALERT_MINUTES))) {
            $problems['scheduler-silent'] = "The scheduler has not run a task since {$lastTick->toDateTimeString()} UTC — is the scheduler container running?";
        }

        foreach ($this->pausedProviders() as $provider) {
            $problems["provider-paused:{$provider}"] = "Messages through {$provider} are paused after repeated provider failures; they will be sent when it recovers.";
        }

        if ((int) config('queue.connections.database.retry_after') <= (int) config('queue.worker_max_timeout')) {
            $problems['retry-after'] = 'DB_QUEUE_RETRY_AFTER is not above the longest worker timeout — a running job can be handed to a second worker.';
        }

        return $problems;
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
        ];
    }
}
