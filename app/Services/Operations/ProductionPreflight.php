<?php

namespace App\Services\Operations;

use App\Services\Tenancy\TenantPermissionRegistrar;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * SaaS-7 (C1, C13, S7-05): is this configuration safe to serve production? Read from the loaded
 * configuration only, never printing a value (names and verdicts). Two levels:
 * - blocker: production must not start (the entrypoint stops the container — PREFLIGHT_ENFORCE);
 * - warning: start, but an operator must act (reported by `ops:preflight` and the release runbook).
 *
 * Outside production every check is reported as a warning at most (development defaults are
 * expected there).
 */
class ProductionPreflight
{
    /**
     * @return list<array{check: string, level: 'blocker'|'warning', message: string}>
     */
    public function problems(): array
    {
        $problems = [];
        $add = function (string $check, string $level, string $message) use (&$problems): void {
            $problems[] = ['check' => $check, 'level' => $level, 'message' => $message];
        };

        $key = (string) config('app.key');
        $cipher = (string) config('app.cipher');
        $decodedKey = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        if ($key === '' || $decodedKey === false || ! Encrypter::supported((string) $decodedKey, $cipher)) {
            $add('app_key', 'blocker', 'APP_KEY is missing or not a valid key for '.$cipher.'.');
        }

        if ((bool) config('app.debug')) {
            $add('app_debug', 'blocker', 'APP_DEBUG is on: errors would show code, queries and configuration to visitors.');
        }

        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $add('app_url', 'blocker', 'APP_URL must be the public https:// origin (links, signed URLs, cookies).');
        }

        if (! (bool) config('session.secure')) {
            $add('session_secure', 'blocker', 'SESSION_SECURE_COOKIE must be true: session cookies would travel over plain http.');
        }

        $cacheStore = (string) config('cache.default');
        $cacheDriver = (string) config("cache.stores.{$cacheStore}.driver");

        if (in_array($cacheDriver, ['array', 'file', 'null'], true)) {
            $add('cache_shared', 'blocker', "CACHE_STORE ({$cacheDriver}) is per process or per container: locks, rate limits, overlap guards and heartbeats must be shared.");
        }

        if ($cacheDriver === 'database') {
            $default = (string) config('database.default');
            $connection = (string) (config("cache.stores.{$cacheStore}.connection") ?: $default);
            $lockConnection = (string) (config("cache.stores.{$cacheStore}.lock_connection") ?: $connection);

            if ($connection === $default) {
                $add('cache_connection', 'blocker', 'The database cache store must keep its data on its own connection (DB_CACHE_CONNECTION=mysql_cache): on the default connection, cache writes and rate-limit counters join business transactions.');
            }

            if ($lockConnection !== $default) {
                $add('cache_locks', 'blocker', "Cache locks must stay on the business connection (DB_CACHE_LOCK_CONNECTION={$default}): on [{$lockConnection}] a lock taken inside a transaction is released before that transaction's work is visible, and survives its rollback.");
            }
        }

        if (in_array((string) config('queue.default'), ['sync', 'null'], true)) {
            $add('queue_async', 'blocker', 'QUEUE_CONNECTION is '.config('queue.default').': mail, webhooks and AI would run inside web requests or not at all.');
        }

        if (in_array((string) config('mail.default'), ['log', 'array'], true)) {
            $add('mail_transport', 'blocker', 'MAIL_MAILER is '.config('mail.default').': mail is not sent, and the log mailer writes reset links and codes into the log.');
        }

        $dbPassword = (string) config('database.connections.'.config('database.default').'.password');

        if (config('database.default') !== 'sqlite' && in_array($dbPassword, ['', 'secret', 'password', 'root'], true)) {
            $add('db_password', 'blocker', 'The database password is empty or a known default.');
        }

        $registrar = app(PermissionRegistrar::class);

        if (! $registrar instanceof TenantPermissionRegistrar) {
            $add('permission_cache', 'blocker', 'The permission registrar is not tenant-scoped: the global map runs out of memory as tenants grow.');
        }

        if (blank(config('queue.health_token'))) {
            $add('health_token', 'warning', 'QUEUE_HEALTH_TOKEN is not set: /health/queue (and readiness details) cannot be monitored.');
        }

        if (config('app.trusted_proxies') === null) {
            $add('trusted_proxies', 'warning', 'TRUSTED_PROXIES is not set: behind a load balancer every client shares one IP (rate limits) and https is not detected.');
        }

        if (config('app.trusted_hosts') === []) {
            $add('trusted_hosts', 'warning', 'APP_TRUSTED_HOSTS is not set: any Host header is served.');
        }

        if ((int) config('logging.channels.daily.max_files') === 0) {
            $add('log_retention', 'warning', 'LOG_DAILY_DAYS is 0: logs are kept forever (owner decision D-S7-O9).');
        }

        if (in_array((string) config('logging.channels.daily.level'), ['debug'], true)) {
            $add('log_level', 'warning', 'LOG_LEVEL is debug in production.');
        }

        if (array_filter((array) config('app.previous_keys')) !== []) {
            $add('previous_keys', 'warning', 'APP_PREVIOUS_KEYS is set: run security:reencrypt, drain the queues, then remove the old key.');
        }

        try {
            if (! app(AuditProtection::class)->installed()) {
                $add('audit_protection', 'warning', 'The audit trail is append-only in the application only: run audit:protect install with a privileged database user (D-S7-O8).');
            }
        } catch (Throwable) {
            $add('audit_protection', 'warning', 'Could not read the audit triggers (is the database reachable and migrated?).');
        }

        if (app()->environment('production')) {
            return $problems;
        }

        return array_map(fn (array $problem): array => [...$problem, 'level' => 'warning'], $problems);
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
