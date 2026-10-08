<?php

namespace App\Console\Commands;

use App\Services\Operations\AuditProtection;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * SaaS-7 (C10): installs, removes or reports the database triggers that make audit_logs
 * append-only (AuditProtection). Run by a database administrator with credentials that may create
 * triggers — e.g. `DB_USERNAME=<admin> DB_PASSWORD=… php artisan audit:protect install` — or with
 * --database=<a connection configured with them>. Idempotent.
 */
#[Signature('audit:protect {action=status : install, remove or status} {--database= : The connection to use (default: the default connection)}')]
#[Description('Make audit_logs append-only in the database (install/remove/status of the triggers)')]
class AuditProtect extends Command
{
    public function handle(AuditProtection $protection): int
    {
        $protection = $protection->onConnection($this->option('database') ?: null);
        $action = (string) $this->argument('action');

        try {
            match ($action) {
                'install' => $protection->install(),
                'remove' => $protection->remove(),
                'status' => null,
                default => throw new \InvalidArgumentException("Unknown action [{$action}]: use install, remove or status."),
            };
        } catch (Throwable $e) {
            $this->error($e instanceof \InvalidArgumentException ? $e->getMessage() : 'Could not change the audit triggers ('.class_basename($e).'): this needs a database user that may create triggers.');

            return self::FAILURE;
        }

        $installed = $protection->installed();
        $this->line($installed ? 'Audit trail is append-only in the database (triggers installed).' : 'Audit trail is protected by the application only (triggers not installed).');

        return $action === 'status' || $installed === ($action === 'install') ? self::SUCCESS : self::FAILURE;
    }
}
