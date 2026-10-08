<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * SaaS-7 (C9): can this instance serve traffic right now? Each dependency the web tier needs on
 * every request, checked for real and cheaply:
 * - database: `select 1` on the default connection;
 * - cache: write, read back and forget a throw-away key on the default store (sessions, locks,
 *   rate limits and entitlements live there);
 * - storage: write, read back and delete a throw-away file on the private disk.
 *
 * Results are booleans only — never an exception message, host name or path, so the probe can be
 * answered publicly. Liveness (the process answers) needs none of this (HealthController::live).
 */
class ReadinessProbe
{
    public const array CHECKS = ['database', 'cache', 'storage'];

    /**
     * @return array{ok: bool, checks: array<string, bool>}
     */
    public function run(): array
    {
        $checks = [
            'database' => $this->attempt(fn (): bool => (int) DB::selectOne('select 1 as ok')->ok === 1),
            'cache' => $this->attempt(function (): bool {
                $key = 'health:ready:'.Str::random(16);
                Cache::put($key, 'ok', 30);
                $ok = Cache::get($key) === 'ok';
                Cache::forget($key);

                return $ok;
            }),
            'storage' => $this->attempt(function (): bool {
                $path = 'health/ready-'.Str::random(16).'.txt';
                Storage::disk('local')->put($path, 'ok');
                $ok = Storage::disk('local')->get($path) === 'ok';
                Storage::disk('local')->delete($path);

                return $ok;
            }),
        ];

        return ['ok' => ! in_array(false, $checks, true), 'checks' => $checks];
    }

    /**
     * @param  callable(): bool  $check
     */
    private function attempt(callable $check): bool
    {
        try {
            return $check();
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
