<?php

use App\Enums\AiToolCallStatus;
use App\Enums\CommunicationStatus;
use App\Enums\PlatformEventSeverity;
use App\Jobs\RunTenantScheduledTask;
use App\Mail\PlatformEventMail;
use App\Models\AiToolCall;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\CandidateCommunication;
use App\Models\IntegrationConnection;
use App\Models\PlatformEvent;
use App\Models\Tenant;
use App\Services\QueueHealthService;
use App\Services\SchedulerHeartbeat;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantWorkProbes;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
 * SaaS-7 (C5): the scheduler's tenant fan-out reaches only tenants with work for frequent tasks,
 * the health check makes one pass for every tenant, a long background pass never outlives its
 * lock, and a failing or stale task — or any platform health problem — reaches the operators.
 */
beforeEach(function (): void {
    $this->quiet = Tenant::factory()->create(['slug' => 'quiet']);
});

/**
 * @return list<int> tenant ids queued for $task
 */
function schedulerQueuedFor(string $task, array $options = [], bool $earlierRunsFinished = true): array
{
    // A finished run has released its unique lock (the fake runs nothing).
    if ($earlierRunsFinished) {
        Tenant::query()->each(fn (Tenant $tenant) => TenantContext::current()->run($tenant, fn () => (new UniqueLock(Cache::store()))->release(new RunTenantScheduledTask($task))));
    }

    Bus::fake([RunTenantScheduledTask::class]);
    test()->artisan('tenants:dispatch', ['task' => $task, ...$options])->assertSuccessful();

    return Bus::dispatched(RunTenantScheduledTask::class)->map->tenantId->sort()->values()->all();
}

test('a frequent task is queued only for tenants holding something it could act on; --all-tenants queues everyone', function (string $task, Closure $work): void {
    $work();

    expect(schedulerQueuedFor($task))->toBe([$this->tenant->id])
        ->and(schedulerQueuedFor($task, ['--all-tenants' => true]))->toBe([$this->tenant->id, $this->quiet->id]);
})->with([
    'automation process (active rule)' => ['recruitment:automation:process', fn () => AutomationRule::factory()->active()->create()],
    'automation process (a pending run whose rule is no longer active)' => ['recruitment:automation:process', fn () => AutomationExecution::factory()->create()],
    'automation dispatch (active rule)' => ['recruitment:automation:dispatch', fn () => AutomationRule::factory()->active()->create()],
    'AI action expiry (pending action)' => ['ai:expire-pending-actions', fn () => AiToolCall::factory()->create(['status' => AiToolCallStatus::Pending->value, 'requires_confirmation' => true])],
    // A message queued a moment ago is not stuck yet: the probe is a superset of the sweep's work.
    'reliability sweep (queued message)' => ['reliability:sweep', fn () => CandidateCommunication::factory()->create(['status' => CommunicationStatus::Queued, 'queued_at' => now()])],
    'integrations sweep (a connection)' => ['integrations:sweep', fn () => IntegrationConnection::factory()->create()],
]);

test('tasks without a probe still run for every usable tenant, and closed tenants for none', function (): void {
    $suspended = Tenant::factory()->create(['slug' => 'closed', 'status' => 'suspended']);

    $queued = schedulerQueuedFor('offers:expire-lapsed');

    expect($queued)->toBe([$this->tenant->id, $this->quiet->id])
        ->and($queued)->not->toContain($suspended->id)
        ->and(app(TenantWorkProbes::class)->tenantsFor('offers:expire-lapsed'))->toBeNull();
});

test('a tenant task still queued or running is not queued again by the next tick', function (): void {
    expect(schedulerQueuedFor('offers:expire-lapsed'))->toBe([$this->tenant->id, $this->quiet->id])
        ->and(schedulerQueuedFor('offers:expire-lapsed', earlierRunsFinished: false))->toBe([]);
});

test('a budgeted pass stops starting tenants once the budget is spent and resumes after the last one next time', function (): void {
    $third = Tenant::factory()->create(['slug' => 'third']);
    $visited = [];
    // Each tenant's run takes 40 minutes (the pass logs tenancy.task_finished after each one).
    Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$visited): void {
        if ($log->message === 'tenancy.task_finished' && $log->context['task'] === 'outcomes:evaluate') {
            $visited[] = $log->context['tenant_id'];
            test()->travel(40)->minutes();
        }
    });

    $this->artisan('tenants:run', ['task' => 'outcomes:evaluate', '--all' => true, '--budget' => 3600])->assertSuccessful();
    expect($visited)->toBe([$this->tenant->id, $this->quiet->id]);

    $this->artisan('tenants:run', ['task' => 'outcomes:evaluate', '--all' => true, '--budget' => 3600])->assertSuccessful();
    expect($visited)->toBe([$this->tenant->id, $this->quiet->id, $third->id, $this->tenant->id])
        ->and(Cache::get('tenancy:run-cursor:outcomes:evaluate'))->toBe($this->tenant->id);
});

test('a tenant suspended after the pass read it gets no work from that pass', function (): void {
    $visited = [];
    $skipped = [];
    // The suspension commits while the pass is busy with the first tenant.
    Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$visited, &$skipped): void {
        if ($log->message === 'tenancy.task_finished') {
            $visited[] = $log->context['tenant_id'];
            Tenant::query()->whereKey($this->quiet->id)->update(['status' => 'suspended']);
        }

        if ($log->message === 'tenancy.task_skipped') {
            $skipped[] = $log->context['tenant_id'];
        }
    });

    $this->artisan('tenants:run', ['task' => 'outcomes:evaluate', '--all' => true])->assertSuccessful();

    expect($visited)->toBe([$this->tenant->id])
        ->and($skipped)->toBe([$this->quiet->id]);
});

test('the one-pass tenant health matches each tenant\'s own problems', function (): void {
    $other = Tenant::factory()->create(['slug' => 'other-health']);
    schedulerFailedJob($this->tenant->id);
    TenantContext::current()->run($other, fn () => CandidateCommunication::factory()->create(['status' => CommunicationStatus::Queued, 'queued_at' => now()->subHour()]));
    DB::table('jobs')->insert(['queue' => 'automation', 'payload' => json_encode(['tenant_id' => $other->id, 'displayName' => 'X']), 'attempts' => 0, 'available_at' => now()->subMinutes(40)->getTimestamp(), 'created_at' => now()->subMinutes(40)->getTimestamp()]);

    $byTenant = TenantContext::current()->runWithoutTenant(fn () => app(QueueHealthService::class)->problemsByTenant());

    foreach ([$this->tenant, $other, $this->quiet] as $tenant) {
        $own = TenantContext::current()->run($tenant, fn () => app(QueueHealthService::class)->tenantProblems());
        expect($byTenant[$tenant->id] ?? [])->toEqual($own);
    }

    expect(array_keys($byTenant))->toBe([$this->tenant->id, $other->id]);
});

test('platform health problems become platform events, critical ones mailed at once — not through the queue', function (): void {
    config(['platform.notify_email' => 'ops@example.com', 'queue.expect_processes' => true]);
    Mail::fake();
    app(SchedulerHeartbeat::class);
    Cache::forever(SchedulerHeartbeat::LAST_TICK_KEY, now()->subHour()->toIso8601String());

    $this->artisan('queue:health-check')->assertFailed();
    $sentAfterFirstRun = Mail::sent(PlatformEventMail::class)->count();
    $this->artisan('queue:health-check')->assertFailed();

    $events = TenantContext::current()->runWithoutTenant(fn () => PlatformEvent::query()->where('type', 'platform.health')->get());
    $critical = $events->where('severity', PlatformEventSeverity::Critical);

    expect($events->firstWhere('context.problem', 'scheduler-silent')?->severity)->toBe(PlatformEventSeverity::Critical)
        // One mail per critical problem, and the second run within the hour adds nothing.
        ->and($sentAfterFirstRun)->toBe($critical->count())
        ->and(Mail::sent(PlatformEventMail::class)->count())->toBe($critical->count());
    Mail::assertNothingQueued();
});

test('a task that keeps failing, or has not finished for three of its runs, is a problem', function (): void {
    $heartbeat = app(SchedulerHeartbeat::class);
    Cache::forever(SchedulerHeartbeat::LAST_TICK_KEY, now()->toIso8601String());
    $tasks = collect($heartbeat->tasks())->keyBy('task');
    $everyFive = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'integrations:sweep'));
    $hourly = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'billing:sweep'));

    $this->travel(-30)->minutes();
    $heartbeat->record($everyFive, 'finished');
    $this->travelBack();
    $heartbeat->record($everyFive, 'skipped');
    $heartbeat->record($hourly, 'failed');

    $problems = TenantContext::current()->runWithoutTenant(fn () => app(QueueHealthService::class)->problems());

    expect($problems)->toHaveKey('scheduled-task-stale:integrations:sweep')
        ->and($problems)->toHaveKey('scheduled-task-failed:billing:sweep')
        ->and($tasks)->toHaveKey('integrations:sweep');

    $heartbeat->record($everyFive, 'finished');
    expect(TenantContext::current()->runWithoutTenant(fn () => app(QueueHealthService::class)->problems()))->not->toHaveKey('scheduled-task-stale:integrations:sweep');
});

function schedulerFailedJob(int $tenantId): void
{
    DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'communications', 'payload' => json_encode(['tenant_id' => $tenantId, 'displayName' => 'X']), 'exception' => 'RuntimeException: x', 'failed_at' => now()]);
}
