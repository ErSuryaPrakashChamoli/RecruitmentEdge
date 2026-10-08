<?php

namespace App\Services\Operations;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * SaaS-7 (C1, S7-11): every column the application encrypts with APP_KEY, and re-encryption under
 * the current key so a previous key can finally be retired.
 *
 * Laravel decrypts with APP_KEY and then each of APP_PREVIOUS_KEYS, so rotating the key is safe —
 * but nothing ever re-encrypted, so an old (possibly compromised) key had to stay configured
 * forever. reencrypt() rewrites every value with the current key; once it reports no failures, the
 * previous key can be removed. Values are rewritten compare-and-set (only if unchanged since read),
 * row by row, so it is resumable and safe beside live traffic. Plaintext never leaves this method.
 *
 * Platform-level and cross-tenant by nature (a key covers every tenant's rows): values only, by
 * primary key — reviewed crossing (TenancyArchitectureTest).
 */
class EncryptedColumns
{
    /**
     * Table => encrypted columns. An architecture test keeps this equal to the models' encrypted casts.
     *
     * @var array<string, list<string>>
     */
    public const array COLUMNS = [
        'integration_connections' => ['secrets'],
        'calendar_connections' => ['access_token', 'refresh_token'],
        'api_idempotency_keys' => ['response_encrypted'],
        'inbound_webhook_events' => ['payload_encrypted'],
        'users' => ['app_authentication_secret', 'app_authentication_recovery_codes'],
    ];

    /**
     * @return array<string, array{values: int, rewritten: int, unreadable: int}>
     */
    public function reencrypt(bool $dryRun = false): array
    {
        $report = [];

        foreach (self::COLUMNS as $table => $columns) {
            $totals = ['values' => 0, 'rewritten' => 0, 'unreadable' => 0];

            DB::table($table)->select(['id', ...$columns])->orderBy('id')->chunkById(200, function ($rows) use ($table, $columns, $dryRun, &$totals): void {
                foreach ($rows as $row) {
                    foreach ($columns as $column) {
                        $stored = $row->{$column};

                        if ($stored === null || $stored === '') {
                            continue;
                        }

                        $totals['values']++;

                        try {
                            $fresh = Crypt::encryptString(Crypt::decryptString((string) $stored));
                        } catch (DecryptException) {
                            $totals['unreadable']++;

                            continue;
                        }

                        if (! $dryRun) {
                            // Only if nobody changed it meanwhile; a concurrent write already used the current key.
                            DB::table($table)->where('id', $row->id)->where($column, $stored)->update([$column => $fresh]);
                        }

                        $totals['rewritten']++;
                    }
                }
            });

            $report[$table] = $totals;
        }

        return $report;
    }
}
