<?php

use App\Enums\WebhookDeliveryStatus;
use App\Logging\SensitiveDataRedactor;
use App\Models\IntegrationConnection;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use App\Providers\AppServiceProvider;
use App\Services\QueueHealthService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\Feature\Api\ApiWorld;

/*
 * SaaS-7 (C2, C8, C13, S7-10): the signals operators need, without secrets — API request lines,
 * slow queries, integration health — and the deployment guarantees around them.
 */

test('every API request — refused ones too — leaves one api.request line with route, status and duration, never the token', function (): void {
    $world = ApiWorld::build($this->tenant);
    $lines = [];
    Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$lines): void {
        $lines[] = ['message' => $log->message, 'context' => $log->context];
    });

    $this->getJson('/api/v1/me', ApiWorld::headers($world->tokenA))->assertOk();
    $this->getJson('/api/v1/me', ApiWorld::headers('re_'.str_repeat('a', 20).'_'.str_repeat('b', 40)))->assertUnauthorized();

    $requests = collect($lines)->where('message', 'api.request')->values();

    expect($requests)->toHaveCount(2)
        ->and($requests[0]['context'])->toMatchArray(['route' => 'api.v1.me', 'method' => 'GET', 'status' => 200])
        ->and($requests[0]['context']['credential_id'])->toBeInt()
        ->and($requests[0]['context']['duration_ms'])->toBeInt()
        ->and($requests[1]['context'])->toMatchArray(['status' => 401, 'credential_id' => null])
        ->and(json_encode($requests))->not->toContain($world->tokenA)->not->toContain(str_repeat('b', 40));
});

test('a slow query is logged with its SQL and duration but never its bindings', function (): void {
    config(['database.slow_query_ms' => 1]);
    // Register the listener as the provider does at boot (now with the 1 ms threshold).
    (new ReflectionMethod(AppServiceProvider::class, 'configureHealthCheck'))->invoke(app()->getProvider(AppServiceProvider::class));
    $lines = [];
    Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$lines): void {
        $lines[] = ['message' => $log->message, 'context' => $log->context];
    });

    DB::select("WITH RECURSIVE n(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM n WHERE x < 300000) SELECT count(*) AS c FROM n WHERE ? <> 'x'", ['priya.secret@example.com']);

    $slow = collect($lines)->firstWhere('message', 'db.slow_query');

    expect($slow)->not->toBeNull()
        ->and($slow['context']['sql'])->toContain('WITH RECURSIVE')
        ->and($slow['context']['ms'])->toBeInt()
        ->and(json_encode($slow))->not->toContain('priya.secret');
});

test('the platform health snapshot counts integration delivery health across tenants', function (): void {
    $endpoint = IntegrationConnection::factory()->create(['consecutive_failures' => 6]);
    $event = WebhookEvent::query()->create(['event_key' => 'evt_obs', 'type' => 'candidate.created', 'subject_type' => 'candidate', 'subject_id' => 1, 'payload' => [], 'occurred_at' => now()]);
    WebhookDelivery::query()->create(['webhook_event_id' => $event->id, 'integration_connection_id' => $endpoint->id, 'delivery_key' => 'dlv_obs', 'status' => WebhookDeliveryStatus::Failed])->forceFill(['failed_at' => now()])->save();

    $snapshot = TenantContext::current()->runWithoutTenant(fn () => app(QueueHealthService::class)->snapshot());

    expect($snapshot['integrations'])->toBe(['deliveries_failed_last_hour' => 1, 'deliveries_retrying' => 0, 'endpoints_circuit_open' => 1, 'inbound_failed_last_hour' => 0]);
});

test('trusted proxies come from configuration; unset, no forwarded header is believed', function (): void {
    $this->get('/health/live', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.9']);
    expect(request()->isSecure())->toBeFalse();

    config(['app.trusted_proxies' => '*']);
    TrustProxies::at(config('app.trusted_proxies'));
    $seen = null;
    Route::get('saas7-proxy-probe', function () use (&$seen) {
        $seen = [request()->isSecure(), request()->ip()];

        return 'ok';
    });

    $this->get('/saas7-proxy-probe', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.9']);
    TrustProxies::flushState();

    expect($seen)->toBe([true, '203.0.113.9']);
});

test('logs mask webhook secrets, API tokens (not their key id), password hashes, OAuth tokens and JWTs', function (): void {
    $text = 'whsec_ABCDEFGHIJKLMNOPQRSTUVWX0123456789abcd re_ABCDEFGHIJ0123456789_'.str_repeat('Z', 40).' $2y$12$'.str_repeat('a', 53).' ya29.a0AfB_byCdef eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0In0.c2lnbmF0dXJlcw';

    $redacted = SensitiveDataRedactor::text($text);

    expect($redacted)->toContain('re_ABCDEFGHIJ0123456789_[redacted]')
        ->and(str_contains($redacted, 'whsec_ABCDEF'))->toBeFalse()
        ->and(str_contains($redacted, str_repeat('Z', 40)))->toBeFalse()
        ->and(str_contains($redacted, '$2y$12$'))->toBeFalse()
        ->and(str_contains($redacted, 'ya29.'))->toBeFalse()
        ->and(str_contains($redacted, 'eyJhbGci'))->toBeFalse()
        ->and(SensitiveDataRedactor::context(['secrets' => ['current' => 'x'], 'client_secret' => 'y', 'payload' => '{}', 'id' => 5]))->toBe(['secrets' => '[redacted]', 'client_secret' => '[redacted]', 'payload' => '[redacted]', 'id' => 5]);
});

test('compose runs the cache data on its own connection and its locks on the business one, keeps maintenance in the shared cache and lets workers drain while down; the entrypoint enforces preflight; mail has a timeout', function (): void {
    $compose = Yaml::parseFile(base_path('docker-compose.yml'));
    $environment = $compose['x-app-environment'];
    $workers = collect($compose['services'])->filter(fn (array $service): bool => ($service['command'][2] ?? null) === 'queue:work');
    $entrypoint = (string) file_get_contents(base_path('docker/entrypoint.sh'));

    expect($environment)->toMatchArray(['DB_CACHE_CONNECTION' => 'mysql_cache', 'DB_CACHE_LOCK_CONNECTION' => $environment['DB_CONNECTION'], 'APP_MAINTENANCE_DRIVER' => 'cache', 'APP_MAINTENANCE_STORE' => 'database'])
        ->and($workers)->not->toBeEmpty()
        ->and($workers->every(fn (array $service): bool => in_array('--force', $service['command'], true)))->toBeTrue()
        ->and(strpos($entrypoint, 'php artisan ops:preflight'))->toBeGreaterThan((int) strpos($entrypoint, 'php artisan config:cache'))
        ->and($entrypoint)->toContain('exit 1')
        ->and(config('mail.mailers.smtp.timeout'))->toBe(30);
});
