<?php

use App\Jobs\SummarizeHiringMemoryJob;
use App\Services\QueueHealthService;
use App\Services\SchedulerHeartbeat;
use App\Services\WorkerHeartbeat;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Phase 8.9 (P89-OPS-002/006/007): the platform can say whether it is healthy — /up checks the
 * database, workers and the scheduler report a heartbeat that queue health watches where processes
 * are expected, and every processed job leaves a trace (class, queue, attempt, duration).
 */
test('GET /up reports healthy, and unhealthy when the database is unreachable', function (): void {
    $this->get('/up')->assertOk();

    $default = config('database.default');
    config(['app.debug' => false, 'database.connections.p89_unreachable' => ['driver' => 'sqlite', 'database' => '/nonexistent-dir/p89-health.sqlite'], 'database.default' => 'p89_unreachable']);

    try {
        $this->get('/up')->assertServerError();
    } finally {
        config(['database.default' => $default]);
    }
});

test('a looping worker records its heartbeat at most once a minute', function (): void {
    event(new Looping('database', 'communications,default'));
    $first = Cache::get(WorkerHeartbeat::KEY_PREFIX.'communications,default');
    $this->travel(10)->seconds();
    event(new Looping('database', 'communications,default'));

    expect($first)->not->toBeNull()
        ->and(Cache::get(WorkerHeartbeat::KEY_PREFIX.'communications,default'))->toBe($first)
        ->and(app(WorkerHeartbeat::class)->lastBeats()['communications,default']?->toIso8601String())->toBe($first);
});

test('where workers and the scheduler are expected, queue health reports a silent one', function (): void {
    $problems = fn (): array => app(QueueHealthService::class)->problems();

    expect(collect($problems())->keys()->filter(fn (string $key) => str_starts_with($key, 'worker-silent') || $key === 'scheduler-silent')->all())->toBe([]);

    config(['queue.expect_processes' => true]);
    foreach (config('queue.workers') as $queues) {
        Cache::forever(WorkerHeartbeat::KEY_PREFIX.$queues, now()->toIso8601String());
    }
    Cache::forever(WorkerHeartbeat::KEY_PREFIX.'security,notifications,default', now()->subMinutes(7)->toIso8601String());
    Cache::forget(SchedulerHeartbeat::LAST_TICK_KEY);

    expect(array_keys($problems()))->toContain('worker-silent:security,notifications,default', 'scheduler-silent')
        ->and(array_keys($problems()))->not->toContain('worker-silent:communications,default')
        ->and(app(QueueHealthService::class)->snapshot()['workers'])->toHaveCount(count(config('queue.workers')));
});

test('a processed job logs its class, queue, attempt and duration', function (): void {
    Log::spy();

    event(new JobProcessing('sync', $job = new SyncJob(app(), json_encode(['uuid' => 'p89-job', 'displayName' => SummarizeHiringMemoryJob::class, 'job' => 'x', 'data' => []]), 'sync', 'intelligence')));
    event(new JobProcessed('sync', $job));

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'queue.job_processed'
        && $context['job'] === SummarizeHiringMemoryJob::class
        && $context['queue'] === $job->getQueue()
        && is_int($context['duration_ms']));
});
