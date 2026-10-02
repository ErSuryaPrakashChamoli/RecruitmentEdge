<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Phase 8.9 (P89-OPS-002/007): each queue worker's pulse. A worker records "alive" in the shared
 * cache while it loops — at most once a minute, so an idle worker polling every few seconds costs one
 * write a minute — keyed by the queue list it consumes. Queue health and GET /health/queue list the
 * last beat of every worker in queue.workers, and report a silent one (a crashed or hung worker no
 * longer goes unnoticed until a job waits 15 minutes on its queue).
 */
class WorkerHeartbeat
{
    public const string KEY_PREFIX = 'queue:worker-heartbeat:';

    public const int WRITE_EVERY_SECONDS = 60;

    /**
     * @var array<string, int> queue list => last write (this process)
     */
    private static array $lastWrite = [];

    public function beat(string $queues): void
    {
        $now = now()->getTimestamp();

        if (($now - (self::$lastWrite[$queues] ?? 0)) < self::WRITE_EVERY_SECONDS) {
            return;
        }

        self::$lastWrite[$queues] = $now;
        Cache::forever(self::KEY_PREFIX.$queues, now()->toIso8601String());
    }

    /**
     * @return array<string, Carbon|null> every expected worker's queue list => its last beat
     */
    public function lastBeats(): array
    {
        return collect((array) config('queue.workers', []))
            ->mapWithKeys(fn (string $queues): array => [$queues => is_string($at = Cache::get(self::KEY_PREFIX.$queues)) ? Carbon::parse($at) : null])
            ->all();
    }
}
