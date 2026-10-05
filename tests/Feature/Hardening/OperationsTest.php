<?php

use App\Console\Commands\OpsMigrate;
use App\Models\AuditLog;
use App\Models\IntegrationConnection;
use App\Services\Operations\AuditProtection;
use App\Services\Operations\EncryptedColumns;
use App\Services\Operations\ProductionPreflight;
use App\Services\Operations\ReadinessProbe;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/*
 * SaaS-7 operations: liveness/readiness (C9), production preflight (C13), audit immutability in
 * the database (C10), re-encryption for APP_KEY rotation (C1) and the post-restore integrity check
 * (C12).
 */

function operationsUseKey(string $key, array $previous = []): void
{
    config(['app.key' => $key, 'app.previous_keys' => $previous]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
}

function operationsSafeProduction(): void
{
    app()['env'] = 'production';
    config([
        'app.key' => 'base64:'.base64_encode(Encrypter::generateKey('AES-256-CBC')),
        'app.debug' => false,
        'app.url' => 'https://hire.example.com',
        'session.secure' => true,
        'cache.default' => 'database',
        'cache.stores.database.connection' => 'mysql_cache',
        // Locks on the business connection (whichever database this suite runs on).
        'cache.stores.database.lock_connection' => config('database.default'),
        'queue.default' => 'database',
        'mail.default' => 'smtp',
        'database.connections.mysql.password' => 'a-long-generated-password',
    ]);
}

test('liveness answers without touching any dependency; readiness reports ok publicly and details only to the token', function (): void {
    config(['queue.health_token' => 'ops-token']);

    $this->getJson('/health/live')->assertOk()->assertExactJson(['status' => 'ok'])->assertCookieMissing(config('session.cookie'));
    $this->getJson('/health/ready')->assertOk()->assertExactJson(['status' => 'ok']);
    $this->getJson('/health/ready', ['Authorization' => 'Bearer ops-token'])->assertOk()->assertExactJson(['status' => 'ok', 'checks' => ['database' => true, 'cache' => true, 'storage' => true]]);
    $this->getJson('/health/ready', ['Authorization' => 'Bearer wrong'])->assertExactJson(['status' => 'ok']);
});

test('a failing dependency makes readiness 503 without revealing which or why to the public', function (): void {
    config(['queue.health_token' => 'ops-token', 'cache.default' => 'broken', 'cache.stores.broken' => ['driver' => 'database', 'connection' => 'missing-connection', 'table' => 'cache']]);

    $this->getJson('/health/ready')->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
    $this->getJson('/health/ready', ['Authorization' => 'Bearer ops-token'])->assertStatus(503)->assertJsonPath('checks.cache', false)->assertJsonPath('checks.database', true);
    $this->getJson('/health/live')->assertOk();
    expect(app(ReadinessProbe::class)->run()['ok'])->toBeFalse();
});

test('liveness and readiness keep answering in maintenance mode, while the application does not', function (): void {
    $this->app->maintenanceMode()->activate([]);

    try {
        $this->get('/health/live')->assertOk();
        $this->get('/health/ready')->assertOk()->assertExactJson(['status' => 'ok']);
        $this->get('/admin/login')->assertServiceUnavailable();
    } finally {
        $this->app->maintenanceMode()->deactivate();
    }
});

test('preflight passes a safe production configuration', function (): void {
    operationsSafeProduction();

    expect(ProductionPreflight::hasBlockers(app(ProductionPreflight::class)->problems()))->toBeFalse();
});

test('preflight blocks an unsafe production configuration', function (array $unsafe, string $check): void {
    $default = config('database.default');
    operationsSafeProduction();
    config($unsafe);

    try {
        $problems = collect(app(ProductionPreflight::class)->problems());
        $exitCode = $this->artisan('ops:preflight')->run();
    } finally {
        // The test database stays the default connection (RefreshDatabase rolls it back).
        config(['database.default' => $default]);
    }

    expect($problems->firstWhere('check', $check))->not->toBeNull()
        ->and($problems->firstWhere('check', $check)['level'])->toBe('blocker')
        ->and($exitCode)->toBe(1);
})->with([
    'no key' => [['app.key' => ''], 'app_key'],
    'a malformed key' => [['app.key' => 'base64:short'], 'app_key'],
    'debug on' => [['app.debug' => true], 'app_debug'],
    'plain http' => [['app.url' => 'http://hire.example.com'], 'app_url'],
    'insecure cookie' => [['session.secure' => false], 'session_secure'],
    'per-process cache' => [['cache.default' => 'array'], 'cache_shared'],
    'cache data on the business connection' => [['cache.stores.database.connection' => null], 'cache_connection'],
    'cache locks off the business connection' => [['cache.stores.database.lock_connection' => 'mysql_cache'], 'cache_locks'],
    'cache locks left to follow the cache data' => [['cache.stores.database.lock_connection' => null], 'cache_locks'],
    'sync queue' => [['queue.default' => 'sync'], 'queue_async'],
    'log mailer' => [['mail.default' => 'log'], 'mail_transport'],
    'default database password' => [['database.default' => 'mysql', 'database.connections.mysql.password' => 'secret'], 'db_password'],
]);

test('preflight warns while critical platform alerts would be mailed to nobody', function (): void {
    operationsSafeProduction();
    $alerts = fn (): ?array => collect(app(ProductionPreflight::class)->problems())->firstWhere('check', 'platform_alerts');

    config(['platform.notify_email' => null]);
    expect($alerts())->toMatchArray(['level' => 'warning']);

    config(['platform.notify_email' => 'platform-ops@example.com']);
    expect($alerts())->toBeNull();
});

test('outside production the same problems are warnings, and the messages never carry a configured value', function (): void {
    config(['app.debug' => true, 'database.connections.mysql.password' => 'p4ss-in-config', 'app.key' => 'base64:short']);

    $problems = app(ProductionPreflight::class)->problems();

    expect(collect($problems)->pluck('level')->unique()->all())->toBe(['warning'])
        ->and(json_encode($problems))->not->toContain('p4ss-in-config');
    $this->artisan('ops:preflight')->assertSuccessful();
});

// On MySQL, CREATE TRIGGER commits the test's wrapping transaction; the same behaviour is proven there by
// the audit race (tests/Concurrency) and the rehearsal smoke, outside any wrapping transaction.
test('with the triggers installed the audit trail cannot be changed or deleted by any query, only appended to', function (): void {
    $entry = AuditLog::record($this->tenant, 'saas7_probe', null, ['n' => 1]);
    $this->artisan('audit:protect install')->assertSuccessful();

    expect(app(AuditProtection::class)->installed())->toBeTrue()
        ->and(fn () => DB::table('audit_logs')->where('id', $entry->id)->update(['action' => 'rewritten']))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::table('audit_logs')->where('id', $entry->id)->delete())->toThrow(QueryException::class, 'append-only')
        ->and(AuditLog::record($this->tenant, 'saas7_after', null, ['n' => 2])->exists)->toBeTrue();

    $this->artisan('audit:protect install')->assertSuccessful();
    $this->artisan('audit:protect remove')->assertSuccessful();
    expect(DB::table('audit_logs')->where('id', $entry->id)->update(['reason' => 'allowed again']))->toBe(1);
})->skip(fn (): bool => DB::getDriverName() !== 'sqlite', 'DDL would commit the test transaction; proven on MySQL by the audit race');

test('re-encryption rewrites every encrypted value under the current key, so the previous key can go', function (): void {
    $oldKey = config('app.key');
    $connection = IntegrationConnection::factory()->create();
    $plain = $connection->secrets;
    $stored = DB::table('integration_connections')->where('id', $connection->id)->value('secrets');

    operationsUseKey('base64:'.base64_encode(Encrypter::generateKey('AES-256-CBC')), [$oldKey]);
    $this->artisan('security:reencrypt --dry-run')->assertSuccessful();
    expect(DB::table('integration_connections')->where('id', $connection->id)->value('secrets'))->toBe($stored);

    $this->artisan('security:reencrypt')->assertSuccessful();
    operationsUseKey(config('app.key'));

    expect(DB::table('integration_connections')->where('id', $connection->id)->value('secrets'))->not->toBe($stored)
        ->and($connection->fresh()->secrets)->toBe($plain);
});

test('a value no configured key can read is reported, and the command fails so no key is removed', function (): void {
    $connection = IntegrationConnection::factory()->create();
    DB::table('integration_connections')->where('id', $connection->id)->update(['secrets' => (new Encrypter(Encrypter::generateKey('AES-256-CBC'), 'AES-256-CBC'))->encryptString('{}')]);

    $this->artisan('security:reencrypt')->assertFailed()->expectsOutputToContain('cannot be decrypted');
});

test('the re-encryption registry lists every encrypted column the models declare', function (): void {
    $declared = [];

    foreach (glob(app_path('Models/*.php')) as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');

        if (! is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        $model = new $class;

        foreach ($model->getCasts() as $column => $cast) {
            if (str_starts_with((string) $cast, 'encrypted')) {
                $declared[$model->getTable()][] = $column;
            }
        }
    }

    ksort($declared);
    $registry = EncryptedColumns::COLUMNS;
    ksort($registry);

    expect(array_map(fn (array $columns): array => collect($columns)->sort()->values()->all(), $declared))
        ->toBe(array_map(fn (array $columns): array => collect($columns)->sort()->values()->all(), $registry));
});

test('the integrity check passes a sound database and names an orphaned reference', function (): void {
    $this->artisan('ops:verify-integrity')->assertSuccessful()->expectsOutputToContain('Integrity OK');

    // An orphan, as a restore loaded with foreign-key checks off can leave.
    DB::getDriverName() === 'sqlite' ? DB::statement('PRAGMA defer_foreign_keys = ON') : DB::statement('SET FOREIGN_KEY_CHECKS = 0');
    DB::table('webhook_deliveries')->insert(['tenant_id' => $this->tenant->id, 'webhook_event_id' => 999999, 'integration_connection_id' => 999999, 'delivery_key' => 'dlv_orphan', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    DB::getDriverName() === 'sqlite' ?: DB::statement('SET FOREIGN_KEY_CHECKS = 1');

    $this->artisan('ops:verify-integrity')->assertFailed()->expectsOutputToContain('webhook_deliveries');
});

test('ops:migrate runs the migrations itself — here none are pending — and names one lock per database', function (): void {
    $this->artisan('ops:migrate')->assertSuccessful()->expectsOutputToContain('Nothing to migrate');

    expect(OpsMigrate::lockName('hrms'))->toBe('migrate:hrms')
        ->and(mb_strlen(OpsMigrate::lockName(str_repeat('d', 80))))->toBe(64);
});
