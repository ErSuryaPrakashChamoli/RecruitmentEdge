<?php

namespace App\Console\Commands;

use App\Services\Operations\IntegrityVerifier;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * SaaS-7 (C12): read-only integrity report after a restore, repair or migration rehearsal
 * (IntegrityVerifier). Exits non-zero on any orphaned foreign key, tenancy violation, failed
 * SaaS-state check, unreadable encrypted value or pending migration; warnings are listed, not
 * failed. Read-only; counts only.
 */
#[Signature('ops:verify-integrity {--json : Print the report as JSON}')]
#[Description('Verify a restored or migrated database: foreign keys, tenancy, SaaS state, encrypted values, migrations, audit protection')]
class OpsVerifyIntegrity extends Command
{
    public function handle(IntegrityVerifier $verifier): int
    {
        $report = TenantContext::current()->runWithoutTenant(fn (): array => $verifier->report());

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT));

            return $report['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $database = $report['database'];
        $this->line("Database: {$database['driver']} {$database['version']}".($database['utc_offset_minutes'] !== null ? ", session {$database['utc_offset_minutes']} min from UTC" : '').'.');
        $this->line("Foreign keys: {$report['foreign_keys']['checked']} checked, ".count($report['foreign_keys']['violations']).' with orphaned rows.');
        $this->line("Tenancy: {$report['tenancy']['checks']} checks, ".count($report['tenancy']['violations']).' with violations.');
        $this->line('SaaS state: '.count($report['saas']['failures']).' failed, '.count($report['saas']['warnings']).' to look at.');
        $this->line("Encrypted values: {$report['encryption']['values']} checked, {$report['encryption']['unreadable']} unreadable with the configured keys.");
        $this->line('Pending migrations: '.count($report['pending_migrations']).'.');
        $this->line('Audit trail append-only in the database: '.($report['audit_protected'] ? 'yes' : 'no (application only)').'.');

        foreach ([...$report['foreign_keys']['violations'], ...$report['tenancy']['violations'], ...$report['saas']['failures']] as $check => $count) {
            $this->warn("  {$check}: {$count}");
        }

        foreach ($report['saas']['warnings'] as $check => $count) {
            $this->line("  (look at) {$check}: {$count}");
        }

        foreach ($report['pending_migrations'] as $migration) {
            $this->warn("  pending: {$migration}");
        }

        $report['ok'] ? $this->info('Integrity OK.') : $this->error('Integrity check failed.');

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
