<?php

namespace App\Console\Commands;

use App\Services\Operations\EncryptedColumns;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * SaaS-7 (C1): re-encrypts every encrypted column with the current APP_KEY (EncryptedColumns).
 * Key rotation: put the old key in APP_PREVIOUS_KEYS, set the new APP_KEY, deploy, run this, drain
 * the queues (queued encrypted jobs still carry the old key), then remove the old key. Exits
 * non-zero if any value could not be decrypted with the configured keys — never remove a key then.
 */
#[Signature('security:reencrypt {--dry-run : Count what would be rewritten, change nothing}')]
#[Description('Re-encrypt every encrypted column with the current APP_KEY, so previous keys can be retired')]
class SecurityReencrypt extends Command
{
    public function handle(EncryptedColumns $columns): int
    {
        $report = TenantContext::current()->runWithoutTenant(fn (): array => $columns->reencrypt((bool) $this->option('dry-run')));

        $this->table(['Table', 'Values', $this->option('dry-run') ? 'Would rewrite' : 'Rewritten', 'Unreadable'], collect($report)->map(fn (array $totals, string $table): array => [$table, $totals['values'], $totals['rewritten'], $totals['unreadable']])->values()->all());

        $unreadable = array_sum(array_column($report, 'unreadable'));

        if ($unreadable > 0) {
            $this->error("{$unreadable} value(s) cannot be decrypted with APP_KEY or APP_PREVIOUS_KEYS. Do not remove any key until they are resolved.");

            return self::FAILURE;
        }

        $this->info($this->option('dry-run') ? 'Dry run: nothing changed.' : 'Every encrypted value now uses the current key.');

        return self::SUCCESS;
    }
}
