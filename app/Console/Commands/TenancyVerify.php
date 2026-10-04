<?php

namespace App\Console\Commands;

use App\Services\Tenancy\TenancyVerifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * SaaS-1: platform-level, read-only tenant integrity report (TenancyVerifier) — counts only.
 * Exits non-zero while any check finds an offending row. Run it after the Tenant #1 backfill and
 * before the enforcing migration, and after any data repair.
 */
#[Signature('tenancy:verify {--json : Print every check as JSON} {--all : List the clean checks too}')]
#[Description('Check that every tenant-owned row, reference and role stays inside one tenant')]
class TenancyVerify extends Command
{
    public function handle(TenancyVerifier $verifier): int
    {
        $report = $verifier->report();
        $violations = array_filter($report, fn (int $count): bool => $count > 0);

        if ($this->option('json')) {
            $this->line((string) json_encode(['checks' => count($report), 'violations' => $violations], JSON_PRETTY_PRINT));
        } else {
            $rows = $this->option('all') ? $report : $violations;
            $this->table(['Check', 'Rows'], collect($rows)->map(fn (int $count, string $check): array => [$check, $count])->values()->all());
            $this->info(count($report).' checks, '.count($violations).' with violations.');
        }

        return $violations === [] ? self::SUCCESS : self::FAILURE;
    }
}
