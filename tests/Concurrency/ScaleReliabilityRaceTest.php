<?php

use App\Console\Commands\OpsMigrate;
use App\Enums\ApiScope;
use App\Enums\ConnectionStatus;
use App\Enums\Entitlement;
use App\Enums\WebhookDeliveryStatus;
use App\Jobs\DeliverWebhook;
use App\Jobs\RunTenantScheduledTask;
use App\Models\ApiCredential;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\IntegrationConnection;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use App\Services\Api\ApiCredentialService;
use App\Services\Api\ApiPrincipal;
use App\Services\Api\ApplicantIntakeService;
use App\Services\Integrations\IntegrationConnectionService;
use App\Services\Operations\AuditProtection;
use App\Services\Platform\Commercial\EntitlementOverrideService;
use App\Services\Platform\Commercial\TenantLifecycleService;
use App\Services\Tenancy\TenantContext;
use App\Services\Webhooks\WebhookDeliveryService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Cache\RateLimiter;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concurrency\Race;
use Tests\Feature\Api\ApiWorld;
use Tests\Feature\Api\FakeHostResolver;

/*
 * SaaS-7: scale and reliability changes under real two-transaction concurrency (MySQL 8.4,
 * REPEATABLE READ), with the cache shared through the database as deployed: its data on its own
 * connection (mysql_cache), its locks on the business connection. Each test has its own tenant.
 * vendor/bin/pest -c phpunit.concurrency.xml
 */
beforeEach(function (): void {
    Race::prepareDatabase();
    Storage::fake('local');
    Storage::fake('public');
    Mail::fake();
    Queue::fake();
    FakeHostResolver::install();

    $this->target = Tenant::factory()->create(['slug' => 'scale-'.Str::lower(Str::random(10))]);
    Race::pinPlan($this->target, 'legacy');
    app(EntitlementOverrideService::class)->set($this->target->fresh(), Entitlement::ApiAccess, true, 'Race');
    app(EntitlementOverrideService::class)->set($this->target->fresh(), Entitlement::IntegrationsWebhooks, true, 'Race');
    TenantContext::current()->setTenant($this->target->fresh());

    (new RolePermissionSeeder)->run();
    $this->owner = User::factory()->create(['email' => 'owner.'.Str::random(8).'@scale-race.test'])->assignRole('chro');
    $this->credential = app(ApiCredentialService::class)->issue($this->owner, 'Race', ApiScope::cases(), 30)['credential'];
    $this->posting = ApiWorld::livePosting($this->target);

    scaleRaceCache();
});

afterEach(function (): void {
    Cache::flush();
    DB::table('cache_locks')->delete();
    DB::table('jobs')->delete();
    scaleRaceCache('array');
    TenantContext::current()->setTenant(Tenant::query()->where('slug', 'concurrency')->firstOrFail());
});

/**
 * The cache as deployed (docker-compose.yml): the database store with its data on `mysql_cache`
 * and its locks on the business connection. Every cached service is rebuilt on the new store.
 */
function scaleRaceCache(string $store = 'database', ?string $data = 'mysql_cache', string $locks = 'mysql'): void
{
    config(['cache.default' => $store, 'cache.stores.database.connection' => $data, 'cache.stores.database.lock_connection' => $locks]);
    app('cache')->forgetDriver(['array', 'database']);
    app()->forgetInstance('cache.store');
    app()->forgetInstance(RateLimiter::class);
    Facade::clearResolvedInstances();
    app(PermissionRegistrar::class)->initializeCache();
    app(PermissionRegistrar::class)->clearPermissionsCollection();
}

/**
 * The real database queue instead of the fake, for races about what reaches the jobs table.
 */
function scaleRaceRealQueue(): void
{
    config(['queue.default' => 'database']);
    app()->forgetInstance('queue');
    Facade::clearResolvedInstance('queue');
}

/**
 * @param  class-string  $job
 */
function scaleRaceQueued(string $job, ?int $tenantId = null): int
{
    return DB::table('jobs')->where('payload->displayName', $job)
        ->when($tenantId !== null, fn ($query) => $query->where('payload->tenant_id', $tenantId))
        ->count();
}

/**
 * Which tables the contender is waiting on (performance_schema), read while it is blocked.
 *
 * @return list<string>
 */
function scaleRaceWaitingOn(): array
{
    return collect(DB::select("SELECT DISTINCT OBJECT_NAME AS object FROM performance_schema.data_locks WHERE LOCK_STATUS = 'WAITING' AND OBJECT_SCHEMA = DATABASE()"))->pluck('object')->all();
}

/**
 * @return array{outcome: string, application: mixed}
 */
function scaleRaceIntake(object $test): array
{
    $principal = new ApiPrincipal(ApiCredential::query()->findOrFail($test->credential->id), User::query()->findOrFail($test->owner->id), Tenant::query()->findOrFail($test->target->id));

    return app(ApplicantIntakeService::class)->viaCredential($principal, $test->posting->id, ['full_name' => 'Race Applicant', 'email' => 'same.applicant@example.com', 'mobile' => '+91 98450 00000', 'privacy_consent' => true]);
}

/**
 * Due deliveries to one endpoint of the current tenant.
 *
 * @return list<int>
 */
function scaleRaceDeliveries(int $count, ?IntegrationConnection $endpoint = null): array
{
    $endpoint ??= IntegrationConnection::factory()->create();

    return array_map(function (int $n) use ($endpoint): int {
        $event = WebhookEvent::query()->create(['event_key' => 'evt_'.Str::lower(Str::random(12)), 'type' => 'candidate.created', 'subject_type' => 'candidate', 'subject_id' => $n, 'payload' => ['object' => 'candidate', 'id' => $n], 'occurred_at' => now()]);

        return WebhookDelivery::query()->create(['webhook_event_id' => $event->id, 'integration_connection_id' => $endpoint->id, 'delivery_key' => 'dlv_'.Str::lower(Str::random(12)), 'status' => WebhookDeliveryStatus::Pending, 'next_attempt_at' => now()->subSecond()])->id;
    }, range(1, $count));
}

/**
 * Runs tenants:run for the target tenant in the contender and reports what the pass did.
 */
function scaleRacePass(Tenant $tenant): never
{
    $outcomes = [];
    Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$outcomes): void {
        if (in_array($log->message, ['tenancy.task_finished', 'tenancy.task_skipped'], true)) {
            $outcomes[] = $log->message;
        }
    });

    Artisan::call('tenants:run', ['task' => 'outcomes:evaluate', '--tenant' => [$tenant->slug]]);

    throw new RuntimeException('pass: '.implode(',', $outcomes));
}

test('two API intakes of the same applicant at once: the second waits for the first one\'s submission lock, then finds its candidate and is held', function (): void {
    $race = Race::run(
        holder: fn () => scaleRaceIntake($this),
        contender: function (): void {
            TenantContext::current()->setTenant(Tenant::query()->findOrFail($this->target->id));

            throw new RuntimeException('outcome '.scaleRaceIntake($this)['outcome']);
        },
        whileBlocked: fn () => scaleRaceWaitingOn(),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['observed'])->toBe(['cache_locks'])
        ->and($race['message'])->toBe('outcome held')
        ->and(Candidate::query()->where('email', 'same.applicant@example.com')->count())->toBe(1);
});

test('permission cache: a map rebuilt from the state before a role change commits is never served after it, and no other tenant\'s map is touched', function (): void {
    $home = Tenant::query()->where('slug', 'concurrency')->firstOrFail();
    $registrar = app(PermissionRegistrar::class);
    TenantContext::current()->run($home, fn () => $registrar->keyFor($home->id));
    $homeGeneration = Cache::get($registrar->generationKey($home->id));
    $role = Role::query()->create(['name' => 'race-reviewer', 'guard_name' => 'web']);
    $reviewer = User::factory()->create(['email' => 'reviewer.'.Str::random(8).'@scale-race.test'])->assignRole($role);

    $race = Race::run(
        holder: fn () => Role::query()->findOrFail($role->id)->givePermissionTo('audit.view'),
        contender: function () use ($reviewer): void {
            // Another worker checks the reviewer while the grant is uncommitted: it builds (and
            // caches) the tenant's map from the committed state.
            TenantContext::current()->setTenant(Tenant::query()->findOrFail($this->target->id));

            if (User::query()->findOrFail($reviewer->id)->hasPermissionTo('audit.view')) {
                throw new RuntimeException('Saw an uncommitted grant.');
            }

            throw new RuntimeException('cached under '.app(PermissionRegistrar::class)->keyFor($this->target->id));
        },
    );

    $stale = Str::after((string) $race['message'], 'cached under ');
    $registrar->clearPermissionsCollection();

    expect($race['blocked'])->toBeFalse()
        ->and($race['message'])->toStartWith('cached under ')
        ->and(Cache::has($stale))->toBeTrue()
        ->and($registrar->keyFor($this->target->id))->not->toBe($stale)
        ->and($reviewer->fresh()->hasPermissionTo('audit.view'))->toBeTrue()
        ->and(Cache::get($registrar->generationKey($home->id)))->toBe($homeGeneration);
});

test('tenant workload cap: at the last delivery of the minute one worker sends and the other defers at once — the budget never waits on a transaction', function (): void {
    config(['api.webhooks.tenant_deliveries_per_minute' => 1]);
    Http::fake(['*' => Http::response('ok', 200)]);
    [$first, $second] = scaleRaceDeliveries(2);

    $race = Race::run(
        holder: fn () => app(WebhookDeliveryService::class)->deliver($first),
        contender: fn () => throw new RuntimeException('delivery '.app(WebhookDeliveryService::class)->deliver($second)),
    );

    $waiting = WebhookDelivery::query()->findOrFail($second);

    expect($race['blocked'])->toBeFalse()
        ->and($race['message'])->toBe('delivery deferred')
        ->and(WebhookDelivery::query()->findOrFail($first)->status)->toBe(WebhookDeliveryStatus::Succeeded)
        ->and($waiting->status)->toBe(WebhookDeliveryStatus::Pending)
        ->and($waiting->attempts)->toBe(0)
        ->and($waiting->next_attempt_at->isFuture())->toBeTrue();
});

test('why the cache data has its own connection: on the business connection the same budget check waits for the first worker\'s transaction', function (): void {
    scaleRaceCache(data: null);
    config(['api.webhooks.tenant_deliveries_per_minute' => 1]);
    Http::fake(['*' => Http::response('ok', 200)]);
    [$first, $second] = scaleRaceDeliveries(2);

    $race = Race::run(
        holder: fn () => app(WebhookDeliveryService::class)->deliver($first),
        contender: fn () => app(WebhookDeliveryService::class)->deliver($second),
        whileBlocked: fn () => scaleRaceWaitingOn(),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['observed'])->toBe(['cache']);
});

test('queue claim: two workers taking from one queue at once never take the same job and never wait for each other', function (): void {
    $queue = fn (): DatabaseQueue => tap(new DatabaseQueue(DB::connection(), 'jobs', 'saas7-race', 330), fn (DatabaseQueue $queue) => $queue->setContainer(app()));
    $ids = [
        $queue()->pushRaw((string) json_encode(['uuid' => (string) Str::uuid(), 'displayName' => 'first', 'job' => 'none', 'data' => []])),
        $queue()->pushRaw((string) json_encode(['uuid' => (string) Str::uuid(), 'displayName' => 'second', 'job' => 'none', 'data' => []])),
    ];

    $race = Race::run(
        holder: fn () => $queue()->pop(),
        contender: fn () => throw new RuntimeException('took '.$queue()->pop()?->getJobId()),
    );

    expect($race['blocked'])->toBeFalse()
        ->and($race['message'])->toBe('took '.$ids[1])
        ->and(DB::table('jobs')->whereIn('id', $ids)->whereNotNull('reserved_at')->count())->toBe(2);
});

test('duplicate scheduled task: two schedulers queueing the same tenant task at once queue it once per tenant', function (): void {
    scaleRaceRealQueue();

    $race = Race::run(
        holder: fn () => Artisan::call('tenants:dispatch', ['task' => 'offers:expire-lapsed']),
        contender: fn () => Artisan::call('tenants:dispatch', ['task' => 'offers:expire-lapsed']),
        whileBlocked: fn () => scaleRaceWaitingOn(),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['observed'])->toBe(['cache_locks'])
        ->and($race['completed'])->toBeTrue()
        ->and(scaleRaceQueued(RunTenantScheduledTask::class, $this->target->id))->toBe(1);
});

test('webhook retry: a copy deferred over budget while another worker attempts the delivery waits for that attempt and keeps the retry it scheduled', function (): void {
    config(['api.webhooks.tenant_deliveries_per_minute' => 1, 'api.webhooks.retry_delays' => [900, 1800]]);
    Http::fake(['*' => Http::response('down', 503)]);
    [$id] = scaleRaceDeliveries(1);

    $race = Race::run(
        holder: fn () => app(WebhookDeliveryService::class)->deliver($id),
        contender: fn () => throw new RuntimeException('delivery '.app(WebhookDeliveryService::class)->deliver($id)),
        whileBlocked: fn () => scaleRaceWaitingOn(),
    );

    $delivery = WebhookDelivery::query()->findOrFail($id);

    expect($race['blocked'])->toBeTrue()
        ->and($race['observed'])->toBe(['webhook_deliveries'])
        ->and($race['message'])->toBe('delivery deferred')
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Retrying)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->next_attempt_at->timestamp)->toBeGreaterThan(now()->addMinutes(14)->timestamp);
});

test('webhook retry: two sweeps re-queueing the same due delivery at once queue one delivery job', function (): void {
    scaleRaceRealQueue();
    scaleRaceDeliveries(1);

    $race = Race::run(
        holder: fn () => Artisan::call('integrations:sweep'),
        contender: fn () => Artisan::call('integrations:sweep'),
        whileBlocked: fn () => scaleRaceWaitingOn(),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['observed'])->toBe(['cache_locks'])
        ->and($race['completed'])->toBeTrue()
        ->and(scaleRaceQueued(DeliverWebhook::class))->toBe(1);
});

test('integration disable vs delivery: an attempt checked before the disable commits records its result after it without undoing it; the next one fails unsent', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    $endpoint = IntegrationConnection::factory()->create();
    [$inFlight, $next] = scaleRaceDeliveries(2, $endpoint);

    $race = Race::run(
        holder: fn () => app(IntegrationConnectionService::class)->disable($endpoint, $this->owner, 'Incident'),
        // No lock is held over the HTTP call (RULE 10): an attempt that passed its check before the
        // disable committed goes out, and waits on the endpoint row only to record its result.
        contender: fn () => throw new RuntimeException('delivery '.app(WebhookDeliveryService::class)->deliver($inFlight)),
        whileBlocked: fn () => scaleRaceWaitingOn(),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['observed'])->toBe(['integration_connections'])
        ->and($race['message'])->toBe('delivery succeeded')
        ->and($endpoint->fresh()->status)->toBe(ConnectionStatus::Disabled)
        ->and(app(WebhookDeliveryService::class)->deliver($next))->toBe('failed')
        ->and(WebhookDelivery::query()->findOrFail($next))->response_status->toBeNull()->last_error->toContain('disabled');
});

test('tenant suspension vs a background pass: the pass waits on the tenant row and gives the suspended tenant no work', function (): void {
    $race = Race::run(
        holder: fn () => app(TenantLifecycleService::class)->suspend($this->target->fresh(), 'Commercial hold'),
        contender: fn () => scaleRacePass($this->target),
        whileBlocked: fn () => scaleRaceWaitingOn(),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['observed'])->toBe(['tenants'])
        ->and($race['message'])->toBe('pass: tenancy.task_skipped');
});

test('tenant deletion vs a background pass: a tenant closed for deletion while the pass reaches it gets no work', function (): void {
    $race = Race::run(
        // SaaS-5: a deletion starts from a closed (cancelled) tenant.
        holder: fn () => app(TenantLifecycleService::class)->cancel($this->target->fresh(), 'Contract ended'),
        contender: fn () => scaleRacePass($this->target),
        whileBlocked: fn () => scaleRaceWaitingOn(),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['observed'])->toBe(['tenants'])
        ->and($race['message'])->toBe('pass: tenancy.task_skipped');
});

test('audit append under the append-only triggers: concurrent appends never wait for each other, and an edit is refused mid-race', function (): void {
    $protection = new AuditProtection;
    $protection->install();

    try {
        $race = Race::run(
            holder: fn () => AuditLog::record($this->target, 'saas7_race_first', null, ['n' => 1]),
            contender: function (): void {
                $second = AuditLog::record(Tenant::query()->findOrFail($this->target->id), 'saas7_race_second', null, ['n' => 2]);

                DB::table('audit_logs')->where('id', $second->id)->update(['action' => 'saas7_race_rewritten']);
            },
        );
    } finally {
        $protection->remove();
    }

    expect($race['blocked'])->toBeFalse()
        ->and($race['message'])->toContain(AuditProtection::MESSAGE)
        ->and(AuditLog::query()->where('action', 'like', 'saas7_race_%')->orderBy('id')->pluck('action')->all())->toBe(['saas7_race_first', 'saas7_race_second']);
});

test('migration lock: a second migration run waits for the one in progress, then finds nothing to migrate', function (): void {
    $lock = OpsMigrate::lockName((string) DB::connection()->getDatabaseName());

    $race = Race::run(
        holder: function () use ($lock): void {
            // A run in progress elsewhere holds this database's migration lock until it ends (here: the commit).
            DB::scalar('SELECT GET_LOCK(?, 0)', [$lock]);
            DB::afterCommit(fn () => DB::scalar('SELECT RELEASE_LOCK(?)', [$lock]));
        },
        contender: function (): void {
            $exit = Artisan::call('ops:migrate', ['--wait' => 30]);

            throw new RuntimeException("exit {$exit}: ".trim(Artisan::output()));
        },
        whileBlocked: fn () => collect(DB::select("SELECT OBJECT_NAME AS name FROM performance_schema.metadata_locks WHERE OBJECT_TYPE = 'USER LEVEL LOCK' AND LOCK_STATUS = 'PENDING'"))->pluck('name')->all(),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['observed'])->toBe([$lock])
        ->and($race['message'])->toStartWith('exit 0')->toContain('Nothing to migrate');
});
