<?php

namespace App\Console\Commands;

use App\Services\Identity\EmployeeLifecycleService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Phase 8.4, hourly: applies every separation whose last working day has passed — employment
 * Separated, login revoked, ownership handoff started. Idempotent and retry-safe (each separation
 * is applied once, in its own transaction); the last effective CHRO is never separated (reported).
 * The access gate already refuses such a login in the meantime.
 */
#[Signature('identity:enforce-separations {--dry-run : Report how many separations are due without applying them}')]
#[Description('Apply separations that have taken effect (employment Separated, access Revoked)')]
class EnforceSeparations extends Command
{
    public function handle(EmployeeLifecycleService $lifecycle): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $counts = $lifecycle->enforceDueSeparations($dryRun);

        $this->info(($dryRun ? '[dry run] due: ' : 'Separations: ').collect($counts)->map(fn (int $n, string $key) => "{$key} {$n}")->implode(', ').'.');

        if (($counts['protected'] ?? 0) > 0) {
            $this->warn('Some separations were not applied because they would remove the last active CHRO. Assign the CHRO role to someone else first.');
        }

        return ($counts['failed'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
