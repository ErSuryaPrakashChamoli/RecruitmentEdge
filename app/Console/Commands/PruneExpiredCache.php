<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8.9 (P89-OPS-009, ED-08 — technical housekeeping, not retention): the database cache store
 * removes an expired entry only when something reads it again, so one-off rate-limiter, dedupe and
 * heartbeat keys stay in `cache` forever. This deletes entries that have already expired — values
 * the application can no longer see — in batches. Nothing that has not expired is touched.
 */
#[Signature('cache:prune-expired {--dry-run : Report the count without deleting}')]
#[Description('Delete expired entries from the database cache table (already invisible to the application)')]
class PruneExpiredCache extends Command
{
    private const int BATCH = 1000;

    public function handle(): int
    {
        $store = config('cache.stores.'.config('cache.default'));

        if (($store['driver'] ?? null) !== 'database') {
            $this->info('The cache store is not the database; nothing to prune.');

            return self::SUCCESS;
        }

        $table = DB::connection($store['connection'] ?? null)->table($store['table'] ?? 'cache');
        $now = now()->getTimestamp();

        if ($this->option('dry-run')) {
            $this->info('[dry run] '.(clone $table)->where('expiration', '<=', $now)->count().' expired cache entr(ies) would be pruned.');

            return self::SUCCESS;
        }

        $pruned = 0;

        do {
            $keys = (clone $table)->where('expiration', '<=', $now)->limit(self::BATCH)->pluck('key');
            $pruned += $keys->isEmpty() ? 0 : (clone $table)->whereIn('key', $keys)->where('expiration', '<=', $now)->delete();
        } while ($keys->count() === self::BATCH);

        $this->info("Pruned {$pruned} expired cache entr(ies).");

        return self::SUCCESS;
    }
}
