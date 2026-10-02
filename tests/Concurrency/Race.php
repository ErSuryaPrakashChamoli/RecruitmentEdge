<?php

namespace Tests\Concurrency;

use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
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

        if (self::$migrated) {
            return;
        }

        $config = config('database.connections.mysql');
        $pdo = new PDO("mysql:host={$config['host']};port={$config['port']}", $config['username'], $config['password']);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}`");
        DB::purge('mysql');

        Artisan::call('migrate:fresh', ['--force' => true]);
        self::$migrated = true;
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
