<?php

namespace App\Filament\Exports\Concerns;

use Carbon\CarbonInterface;

/**
 * Phase 8.9 (P89-PERF-017, ED-06): an export's batch (prepare, one job per 100 rows, completion)
 * runs on the named `exports` queue, consumed by the background worker — never on `default`, the
 * fallback every worker takes last.
 *
 * SaaS-7 (S7-09): a chunk is retried for one hour, not Filament's default of a day — with a retry
 * window set, the worker ignores `tries`, so a poison chunk retried for 24 h (~150 attempts) on a
 * queue every tenant shares.
 */
trait RunsOnExportQueue
{
    public function getJobQueue(): ?string
    {
        return 'exports';
    }

    public function getJobRetryUntil(): ?CarbonInterface
    {
        return now()->addHour();
    }
}
