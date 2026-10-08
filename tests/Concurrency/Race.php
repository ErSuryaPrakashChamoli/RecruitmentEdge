<?php

namespace Tests\Concurrency;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PDO;
use ReflectionProperty;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Phase 8.9 (ED-10, P89-OPS-013): a MySQL-backed harness for real two-transaction races. The test
 * suite proper runs on SQLite, which has no row locks and no REPEATABLE READ snapshots, so lock
 * fixes can only be proven here.
 *
 * Race::run() opens a transaction in this process and runs the "holder" (it takes its locks and
 * writes, uncommitted). It then forks a child on its own connection that runs the "contender" — a
 * second request holding copies loaded before the holder commits. Once the child is blocked in
 * LOCK WAIT (or has finished), the holder commits and the child's outcome is returned. Without the
 * locks under test the contender never blocks and acts on its stale copy.
 *
 * Only ever runs against a database whose name ends in `_concurrency` (phpunit.concurrency.xml).
 */
final class Race
{
    private static bool $migrated = false;

    /**
     * @var array<int, mixed> inherited connections kept alive in a child, never closed by it
     */
    private static array $inherited = [];

    public static function prepareDatabase(): void
    {
        $database = (string) config('database.connections.mysql.database');

        if (config('database.default') !== 'mysql' || ! str_ends_with($database, '_concurrency')) {
            throw new RuntimeException("Refusing to run concurrency tests against [{$database}].");
        }

        if (! self::$migrated) {
            $config = config('database.connections.mysql');
            $pdo = new PDO("mysql:host={$config['host']};port={$config['port']}", $config['username'], $config['password']);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}`");
            DB::purge('mysql');

            Artisan::call('migrate:fresh', ['--force' => true]);
            self::$migrated = true;
        }

        // SaaS-1: races run inside one tenant, as the application always does; a forked contender
        // inherits it.
        $tenant = Tenant::query()->firstOrCreate(['slug' => 'concurrency'], [
            'name' => 'Concurrency', 'status' => TenantStatus::Active, 'timezone' => 'Asia/Kolkata', 'locale' => 'en', 'currency' => 'INR', 'country' => 'IN',
        ]);

        // SaaS-3: the harness tenant has every capability without limits (the legacy plan), unless
        // a race test assigns another plan itself.
        self::pinPlan($tenant, 'legacy');
        TenantContext::current()->setTenant($tenant->refresh());
    }

    /**
     * SaaS-3: fixture — pins $tenant to the latest published version of $code (no audit).
     */
    public static function pinPlan(Tenant $tenant, string $code): void
    {
        $versionId = DB::table('plan_versions')->join('plans', 'plans.id', '=', 'plan_versions.plan_id')
            ->where('plans.code', $code)->where('plan_versions.status', 'published')->orderByDesc('plan_versions.version')->value('plan_versions.id');

        DB::table('tenant_plan_assignments')->where('tenant_id', $tenant->id)->delete();
        DB::table('tenant_plan_assignments')->insert(['tenant_id' => $tenant->id, 'plan_version_id' => $versionId, 'is_current' => true, 'effective_from' => now(), 'source' => 'factory', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenants')->where('id', $tenant->id)->increment('entitlement_version');
    }

    /**
     * @param  Closure(): mixed  $holder  runs inside an open transaction in this process
     * @param  Closure(): mixed  $contender  runs in a forked child; may throw
     * @param  (Closure(): mixed)|null  $whileBlocked  runs here while the contender is blocked, before the commit
     * @return array{completed: bool, exception: ?string, message: ?string, blocked: bool, observed: mixed}
     */
    public static function run(Closure $holder, Closure $contender, ?Closure $whileBlocked = null, int $timeoutSeconds = 20): array
    {
        $resultFile = tempnam(sys_get_temp_dir(), 'p89race');

        DB::beginTransaction();
        $holder();

        $pid = pcntl_fork();

        if ($pid === -1) {
            DB::rollBack();

            throw new RuntimeException('Could not fork the contender.');
        }

        if ($pid === 0) {
            self::runChild($contender, $resultFile);
        }

        $blocked = self::waitForLockWaitOrExit($pid, $timeoutSeconds);
        $observed = ($blocked && $whileBlocked !== null) ? $whileBlocked() : null;
        DB::commit();

        pcntl_waitpid($pid, $status);
        $result = json_decode((string) file_get_contents($resultFile), true) ?? ['completed' => false, 'exception' => 'NoResult', 'message' => 'The contender wrote no result.'];
        @unlink($resultFile);

        return [...$result, 'blocked' => $blocked, 'observed' => $observed];
    }

    /**
     * The child never touches (or closes) the parent's connection: it switches the default to a
     * copy of the connection config and dies by SIGKILL, so no destructor sends COM_QUIT on the
     * shared socket and no PHPUnit shutdown code runs twice.
     */
    private static function runChild(Closure $contender, string $resultFile): never
    {
        self::$inherited[] = DB::connection('mysql');
        config(['database.connections.race' => config('database.connections.mysql')]);
        DB::setDefaultConnection('race');

        // SaaS-7: so is every other connection the parent opened (a shared cache's own
        // `mysql_cache`): kept alive, forgotten, and opened afresh by the child on first use.
        $manager = DB::getFacadeRoot();
        array_push(self::$inherited, ...array_values($manager->getConnections()));
        (new ReflectionProperty($manager, 'connections'))->setValue($manager, []);

        if (config('cache.default') !== 'array') {
            self::freshSharedCache();
        }

        try {
            $contender();
            $result = ['completed' => true, 'exception' => null, 'message' => null];
        } catch (Throwable $e) {
            $result = ['completed' => false, 'exception' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 300)];
        }

        file_put_contents($resultFile, json_encode($result));
        posix_kill(posix_getpid(), SIGKILL);

        exit(0);
    }

    /**
     * SaaS-7: the shared (database) cache as a new worker process sees it — cache stores, the rate
     * limiter and spatie's permission registrar captured the parent's connections; they are rebuilt
     * on the child's own.
     */
    private static function freshSharedCache(): void
    {
        // A store (or its locks) on the business connection uses the child's business connection.
        foreach ((array) config('cache.stores') as $name => $store) {
            foreach (['connection', 'lock_connection'] as $option) {
                if (($store[$option] ?? null) === 'mysql') {
                    config(["cache.stores.{$name}.{$option}" => 'race']);
                }
            }
        }

        app('cache')->forgetDriver(array_keys((array) config('cache.stores')));
        app()->forgetInstance('cache.store');
        app()->forgetInstance(RateLimiter::class);
        Facade::clearResolvedInstances();

        $permissions = app(PermissionRegistrar::class);
        $permissions->initializeCache();
        $permissions->clearPermissionsCollection();
    }

    /**
     * The contender is "blocked" once another connection on this database has been inside the same
     * statement for three consecutive polls 100 ms apart (a locking read waiting on the holder's lock;
     * MySQL can wait for a PK lock during optimisation, where INNODB_TRX does not report LOCK WAIT).
     */
    private static function waitForLockWaitOrExit(int $pid, int $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $previous = null;
        $streak = 0;

        while (microtime(true) < $deadline) {
            if (pcntl_waitpid($pid, $status, WNOHANG) !== 0) {
                return false;
            }

            $running = DB::selectOne(
                "SELECT ID AS id, INFO AS info FROM information_schema.PROCESSLIST WHERE DB = DATABASE() AND ID <> CONNECTION_ID() AND COMMAND IN ('Query', 'Execute') AND INFO IS NOT NULL ORDER BY ID LIMIT 1"
            );
            $current = $running !== null ? $running->id.':'.$running->info : null;
            $streak = ($current !== null && $current === $previous) ? $streak + 1 : 0;
            $previous = $current;

            if ($streak >= 3) {
                return true;
            }

            usleep(100_000);
        }

        return false;
    }
}
