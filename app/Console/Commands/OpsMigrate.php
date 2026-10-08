<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SaaS-7 (C13): `migrate --force`, one run at a time per database, whatever host or container
 * starts it. Two concurrent runs would both see the same migrations pending and run them twice —
 * a data backfill applied twice is corruption. On MySQL a named lock (GET_LOCK) makes a second run
 * wait for the first and then find nothing pending. Not a cache lock (`migrate --isolated`): on a
 * fresh database the cache tables do not exist yet. The lock belongs to this database session, so
 * it is released when the run ends — or if the process dies.
 */
#[Signature('ops:migrate {--wait=900 : Seconds to wait for a migration already running}')]
#[Description('Run the migrations, after any migration already running against this database')]
class OpsMigrate extends Command
{
    public function handle(): int
    {
        $connection = DB::connection();
        $named = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true);
        $lock = self::lockName((string) $connection->getDatabaseName());
        $wait = max(0, (int) $this->option('wait'));

        if ($named && (int) $connection->scalar('SELECT GET_LOCK(?, ?)', [$lock, $wait]) !== 1) {
            Log::warning('ops.migrate_lock_timeout', ['wait_seconds' => $wait]);
            $this->error("Another migration of this database is still running after {$wait}s; nothing was run.");

            return self::FAILURE;
        }

        try {
            return $this->call('migrate', ['--force' => true]);
        } finally {
            if ($named) {
                $connection->scalar('SELECT RELEASE_LOCK(?)', [$lock]);
            }
        }
    }

    /**
     * Named locks are server-wide: one per database (MySQL caps the name at 64 characters).
     */
    public static function lockName(string $database): string
    {
        return mb_substr('migrate:'.$database, 0, 64);
    }
}
