<?php

use App\Filament\Pages\QueueHealth;
use App\Jobs\SendCommunicationJob;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\SchedulerHeartbeat;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Phase 8.7 (D8.7-012/021/028): platform administrators can see and are told about failed jobs,
 * backlogs, stuck work and a silent scheduler — without the page, endpoint or alert ever showing
 * a payload or personal data.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('chro');
    $this->recruiter = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('recruiter');
});

function failedJobRow(array $attributes = []): string
{
    $uuid = (string) Str::uuid();
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'communications',
        'payload' => json_encode(['uuid' => $uuid, 'displayName' => SendCommunicationJob::class, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'maxTries' => 5, 'data' => ['commandName' => SendCommunicationJob::class, 'command' => serialize(new SendCommunicationJob(987654))], 'note' => 'recipient priya@example.com']),
        'exception' => "RuntimeException: Temporary provider failure\n#0 trace",
        'failed_at' => now(),
        ...$attributes,
    ]);

    return $uuid;
}

test('only platform administrators can open the queue health page', function (): void {
    actingAs($this->recruiter);
    expect(QueueHealth::canAccess())->toBeFalse();
    $this->get(QueueHealth::getUrl())->assertForbidden();

    actingAs($this->admin);
    $this->get(QueueHealth::getUrl())->assertOk()->assertSee('Needs attention');
});

test('the page shows failed jobs by class and redacted first line, never their payload', function (): void {
    failedJobRow();
    actingAs($this->admin);

    Livewire::test(QueueHealth::class)
        ->assertSee('SendCommunicationJob')
        ->assertSee('Temporary provider failure')
        ->assertSee('1 job(s) failed in the last hour.')
        ->assertDontSee('priya@example.com');
});

test('retrying a failed job puts it back on its queue and audits who did it and why', function (): void {
    $uuid = failedJobRow();
    actingAs($this->admin);

    Livewire::test(QueueHealth::class)
        ->callAction('retryFailedJob', ['reason' => 'Provider is back'], ['uuid' => $uuid])
        ->assertHasNoActionErrors();

    $audit = AuditLog::query()->where('action', 'failed_job_retried')->sole();

    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeFalse()
        ->and(DB::table('jobs')->where('queue', 'communications')->count())->toBe(1)
        ->and($audit->reason)->toBe('Provider is back')
        ->and($audit->user_id)->toBe($this->admin->id);
});

test('the health endpoint needs an administrator or the monitoring token, and answers 503 while something is wrong', function (): void {
    config(['queue.health_token' => 'monitor-secret-token']);

    $this->getJson(route('health.queue'))->assertUnauthorized();
    $this->getJson(route('health.queue'), ['Authorization' => 'Bearer wrong'])->assertForbidden();
    actingAs($this->recruiter)->getJson(route('health.queue'))->assertForbidden();

    auth()->logout();
    $this->getJson(route('health.queue'), ['Authorization' => 'Bearer monitor-secret-token'])->assertOk()->assertJsonPath('status', 'ok');

    failedJobRow();
    $response = actingAs($this->admin)->getJson(route('health.queue'))->assertStatus(503)->assertJsonPath('status', 'attention');

    expect($response->getContent())->not->toContain('priya@example.com');
});

test('the health check alerts administrators once per problem per hour and reports failure until it is fixed', function (): void {
    failedJobRow();
    DB::table('jobs')->insert(['queue' => 'automation', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->subMinutes(40)->getTimestamp(), 'created_at' => now()->subMinutes(40)->getTimestamp()]);
    Cache::forever(SchedulerHeartbeat::LAST_TICK_KEY, now()->subMinutes(20)->toIso8601String());

    $this->artisan('queue:health-check')->assertFailed();
    $this->artisan('queue:health-check')->assertFailed();

    $titles = $this->admin->notifications()->pluck('data')->map(fn ($data) => $data['body'] ?? '')->all();

    expect($this->admin->notifications()->count())->toBe(3)
        ->and(implode(' | ', $titles))->toContain('failed in the last hour', 'automation queue has waited 40 minutes', 'scheduler has not run')
        ->and($this->recruiter->notifications()->count())->toBe(0);

    DB::table('failed_jobs')->delete();
    DB::table('jobs')->delete();
    Cache::forever(SchedulerHeartbeat::LAST_TICK_KEY, now()->toIso8601String());

    $this->artisan('queue:health-check')->assertSuccessful();
});

test('a retry_after that does not exceed the worker timeout is reported', function (): void {
    config(['queue.connections.database.retry_after' => 90, 'queue.worker_max_timeout' => 300]);

    $this->artisan('queue:health-check')->assertFailed()->expectsOutputToContain('DB_QUEUE_RETRY_AFTER');
});
