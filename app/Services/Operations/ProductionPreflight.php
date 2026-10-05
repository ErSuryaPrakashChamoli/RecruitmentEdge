<?php

namespace App\Services\Operations;

use App\Services\Tenancy\TenantPermissionRegistrar;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * SaaS-7 (C1, C13, S7-05): is this configuration safe to serve? Read from the loaded configuration
 * (plus two read-only database probes), never printing a value: names and verdicts only.
 *
 * Production readiness: every check has a scope, and the environment decides what it means —
 *
 * | scope        | production | staging | development (local, testing, anything else) |
 * |--------------|------------|---------|---------------------------------------------|
 * | `all`        | blocker    | blocker | warning                                     |
 * | `production` | blocker    | warning | warning                                     |
 * | `advice`     | warning    | warning | warning                                     |
 *
 * A blocker stops a production container at start (docker/entrypoint.sh, PREFLIGHT_ENFORCE); a
 * warning is reported for an operator to act on. A developer machine never fails.
 */
class ProductionPreflight
{
    private const array KNOWN_ENVIRONMENTS = ['production', 'staging', 'local', 'development', 'testing'];

    public function __construct(private readonly DatabaseClock $clock) {}

    /**
     * Which set of rules applies here: production, staging or development.
     */
    public function tier(): string
    {
        return match (true) {
            app()->environment('production') => 'production',
            app()->environment('staging') => 'staging',
            default => 'development',
        };
    }

    /**
     * @return list<array{check: string, level: 'blocker'|'warning', message: string}>
     */
    public function problems(): array
    {
        $found = [];
        $add = function (string $check, string $scope, string $message) use (&$found): void {
            $found[] = ['check' => $check, 'scope' => $scope, 'message' => $message];
        };

        $this->application($add);
        $this->dataStores($add);
        $this->operations($add);

        $tier = $this->tier();

        return array_map(fn (array $problem): array => [
            'check' => $problem['check'],
            'level' => match (true) {
                $tier === 'production' && $problem['scope'] !== 'advice' => 'blocker',
                $tier === 'staging' && $problem['scope'] === 'all' => 'blocker',
                default => 'warning',
            },
            'message' => $problem['message'],
        ], $found);
    }

    /**
     * @param  callable(string, string, string): void  $add
     */
    private function application(callable $add): void
    {
        $key = (string) config('app.key');
        $cipher = (string) config('app.cipher');
        $decodedKey = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        if ($key === '' || $decodedKey === false || ! Encrypter::supported((string) $decodedKey, $cipher)) {
            $add('app_key', 'all', 'APP_KEY is missing or not a valid key for '.$cipher.'.');
        }

        if ((bool) config('app.debug')) {
            $add('app_debug', 'all', 'APP_DEBUG is on: errors would show code, queries and configuration to visitors.');
        }

        if (! in_array(app()->environment(), self::KNOWN_ENVIRONMENTS, true)) {
            $add('app_env', 'advice', 'APP_ENV is not one of production, staging, local, development or testing: it is checked as development.');
        }

        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $add('app_url', 'all', 'APP_URL must be the public https:// origin (links, signed URLs, cookies).');
        }

        if (config('app.timezone') !== 'UTC') {
            $add('app_timezone', 'all', 'APP timezone must be UTC: every time is stored in UTC and shown in each tenant\'s own zone; another zone shifts stored times (PR-02).');
        }

        if (! (bool) config('session.secure')) {
            $add('session_secure', 'all', 'SESSION_SECURE_COOKIE must be true: session cookies would travel over plain http.');
        }

        $trustedHosts = (array) config('app.trusted_hosts');
        $appHost = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        if ($trustedHosts === []) {
            $add('trusted_hosts', 'advice', 'APP_TRUSTED_HOSTS is not set: any Host header is served.');
        } elseif ($appHost !== '' && ! in_array($appHost, $trustedHosts, true)) {
            $add('trusted_hosts_app_url', 'all', 'APP_TRUSTED_HOSTS does not include the host of APP_URL: requests to the application\'s own address would be refused.');
        }

        $proxies = config('app.trusted_proxies');

        if ($proxies === null) {
            $add('trusted_proxies', 'advice', 'TRUSTED_PROXIES is not set: behind a load balancer every client shares one IP (rate limits) and https is not detected.');
        } elseif ($proxies === '*') {
            $add('trusted_proxies_any', 'advice', 'TRUSTED_PROXIES is *: forwarded headers are believed from any client — safe only when nothing but the proxy can reach the application.');
        }

        if (in_array('*', (array) config('cors.allowed_origins'), true)) {
            $add('cors_any_origin', 'advice', 'CORS_ALLOWED_ORIGINS allows any browser origin on /api: harmless without a credential (the API takes no cookies), but set the integrators\' origins, or none, with the API exposure decision (PR-01, D-S6-O1).');
        }

        $registrar = app(PermissionRegistrar::class);

        if (! $registrar instanceof TenantPermissionRegistrar) {
            $add('permission_cache', 'all', 'The permission registrar is not tenant-scoped: the global map runs out of memory as tenants grow.');
        }
    }

    /**
     * @param  callable(string, string, string): void  $add
     */
    private function dataStores(callable $add): void
    {
        $default = (string) config('database.default');
        $driver = (string) config("database.connections.{$default}.driver");

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $add('database_driver', 'all', "The default database connection uses {$driver}: the application's locking and concurrency guarantees are proven on MySQL 8.4 only.");
        }

        $dbPassword = (string) config("database.connections.{$default}.password");

        if ($driver !== 'sqlite' && in_array($dbPassword, ['', 'secret', 'password', 'root'], true)) {
            $add('db_password', 'all', 'The database password is empty or a known default.');
        }

        try {
            $offset = $this->clock->utcOffsetMinutes();

            if ($offset !== null && $offset !== 0) {
                $add('db_timezone', 'advice', 'The database session runs '.$offset.' minutes from UTC: database-side NOW() and TIMESTAMP conversion disagree with the application\'s UTC. Pin it (DB_TIMEZONE=+00:00 or the server\'s default_time_zone) — decided with the production-copy rehearsal, since a change alters how existing TIMESTAMP values read (PR-02).');
            }
        } catch (Throwable) {
            $add('db_timezone', 'advice', 'Could not read the database session time zone (is the database reachable?).');
        }

        $cacheStore = (string) config('cache.default');
        $cacheDriver = (string) config("cache.stores.{$cacheStore}.driver");

        if (in_array($cacheDriver, ['array', 'file', 'null'], true)) {
            $add('cache_shared', 'all', "CACHE_STORE ({$cacheDriver}) is per process or per container: locks, rate limits, overlap guards and heartbeats must be shared.");
        }

        if ($cacheDriver === 'database') {
            $connection = (string) (config("cache.stores.{$cacheStore}.connection") ?: $default);
            $lockConnection = (string) (config("cache.stores.{$cacheStore}.lock_connection") ?: $connection);

            if ($connection === $default) {
                $add('cache_connection', 'all', 'The database cache store must keep its data on its own connection (DB_CACHE_CONNECTION=mysql_cache): on the default connection, cache writes and rate-limit counters join business transactions.');
            }

            if ($lockConnection !== $default) {
                $add('cache_locks', 'all', "Cache locks must stay on the business connection (DB_CACHE_LOCK_CONNECTION={$default}): on [{$lockConnection}] a lock taken inside a transaction is released before that transaction's work is visible, and survives its rollback.");
            }
        }

        if (in_array((string) config('queue.default'), ['sync', 'null'], true)) {
            $add('queue_async', 'all', 'QUEUE_CONNECTION is '.config('queue.default').': mail, webhooks and AI would run inside web requests or not at all.');
        }

        $disk = (string) config('filesystems.default');
        $localRoot = rtrim((string) config('filesystems.disks.local.root'), '/').'/';

        if ($disk === 'public' || str_starts_with($localRoot, rtrim(public_path(), '/').'/')) {
            $add('filesystem_private', 'all', 'Private files (resumes, documents, offer letters) would be stored where the web server serves them: FILESYSTEM_DISK must be the private local disk, outside public/.');
        }
    }

    /**
     * @param  callable(string, string, string): void  $add
     */
    private function operations(callable $add): void
    {
        if (in_array((string) config('mail.default'), ['log', 'array'], true)) {
            $add('mail_transport', 'production', 'MAIL_MAILER is '.config('mail.default').': mail is not sent, and the log mailer writes reset links and codes into the log.');
        }

        $notify = (string) config('platform.notify_email');

        if ($notify === '' || filter_var($notify, FILTER_VALIDATE_EMAIL) === false) {
            $add('platform_alerts', 'production', 'PLATFORM_NOTIFY_EMAIL is not a valid address: critical platform events (a silent worker or scheduler, a failing scheduled task, a failed purge) would reach nobody (S7-04, D-S7-O6).');
        }

        if (blank(config('queue.health_token'))) {
            $add('health_token', 'advice', 'QUEUE_HEALTH_TOKEN is not set: /health/queue (and readiness details) cannot be monitored.');
        }

        if ((int) config('logging.channels.daily.max_files') === 0) {
            $add('log_retention', 'advice', 'LOG_DAILY_DAYS is 0: logs are kept forever (owner decision D-S7-O9).');
        }

        if (in_array((string) config('logging.channels.daily.level'), ['debug'], true)) {
            $add('log_level', 'advice', 'LOG_LEVEL is debug in production.');
        }

        if (array_filter((array) config('app.previous_keys')) !== []) {
            $add('previous_keys', 'advice', 'APP_PREVIOUS_KEYS is set: run security:reencrypt, drain the queues, then remove the old key.');
        }

        try {
            if (! app(AuditProtection::class)->installed()) {
                $add('audit_protection', 'advice', 'The audit trail is append-only in the application only: run audit:protect install with a privileged database user, or record the decision to accept it (D-S7-O8).');
            }
        } catch (Throwable) {
            $add('audit_protection', 'advice', 'Could not read the audit triggers (is the database reachable and migrated?).');
        }
    }

    /**
     * @param  list<array{check: string, level: string, message: string}>  $problems
     */
    public static function hasBlockers(array $problems): bool
    {
        return collect($problems)->contains(fn (array $problem): bool => $problem['level'] === 'blocker');
    }

    /**
     * For the log: names and levels only.
     *
     * @param  list<array{check: string, level: string, message: string}>  $problems
     */
    public static function summary(array $problems): string
    {
        return collect($problems)->map(fn (array $problem): string => Str::upper($problem['level']).':'.$problem['check'])->implode(', ');
    }
}
