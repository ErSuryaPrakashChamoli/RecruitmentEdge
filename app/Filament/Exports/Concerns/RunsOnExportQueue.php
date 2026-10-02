<?php

namespace App\Filament\Exports\Concerns;

/**
 * Phase 8.9 (P89-PERF-017, ED-06): an export's batch (prepare, one job per 100 rows, completion)
 * runs on the named `exports` queue, consumed by the background worker — never on `default`, the
 * fallback every worker takes last.
 */
trait RunsOnExportQueue
{
    public function getJobQueue(): ?string
    {
        return 'exports';
    }
}
