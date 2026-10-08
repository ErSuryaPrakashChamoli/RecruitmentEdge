<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SaaS-7 (C10, closes S5-R1 as far as code can): the audit trail is append-only in the database
 * too. Two triggers refuse every UPDATE and DELETE on audit_logs, whatever path issues it — the
 * query builder, raw SQL, a future migration — not only Eloquent (AuditLog's model guard).
 *
 * Installed by an explicit command (audit:protect install), not a migration: MySQL with binary
 * logging (the default) refuses CREATE TRIGGER to a non-SUPER user unless
 * log_bin_trust_function_creators is on, so the production app user usually cannot create them.
 * A database administrator runs the command once with privileged credentials; preflight and the
 * integrity check report whether they are present. Revoking UPDATE/DELETE on audit_logs from the
 * app user (and DROP/TRIGGER) is the infrastructure half (docs/saas-7-security-review.md).
 *
 * Compatible with the rest of the system: rows are only ever inserted; foreign-key cascades
 * (users nullOnDelete) do not fire MySQL triggers; tenant purge retains audit_logs; DDL (adding a
 * column) is unaffected. A future data migration that must rewrite audit rows removes the
 * triggers first and reinstalls them after (documented in the runbook).
 */
class AuditProtection
{
    public const array TRIGGERS = ['audit_logs_append_only_update', 'audit_logs_append_only_delete'];

    public const string MESSAGE = 'audit_logs is append-only';

    public function __construct(private readonly ?string $connection = null) {}

    public function onConnection(?string $connection): self
    {
        return new self($connection);
    }

    public function installed(): bool
    {
        return count(array_intersect(self::TRIGGERS, $this->existing())) === count(self::TRIGGERS);
    }

    public function install(): void
    {
        $db = DB::connection($this->connection);

        foreach (['update' => 'UPDATE', 'delete' => 'DELETE'] as $suffix => $event) {
            $name = "audit_logs_append_only_{$suffix}";

            if (in_array($name, $this->existing(), true)) {
                continue;
            }

            $db->unprepared(match ($db->getDriverName()) {
                'mysql', 'mariadb' => "CREATE TRIGGER {$name} BEFORE {$event} ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MESSAGE."'",
                'sqlite' => "CREATE TRIGGER {$name} BEFORE {$event} ON audit_logs BEGIN SELECT RAISE(ABORT, '".self::MESSAGE."'); END",
                default => throw new RuntimeException("Audit protection is not implemented for the {$db->getDriverName()} driver."),
            });
        }
    }

    public function remove(): void
    {
        $db = DB::connection($this->connection);

        foreach (self::TRIGGERS as $name) {
            $db->unprepared("DROP TRIGGER IF EXISTS {$name}");
        }
    }

    /**
     * @return list<string>
     */
    private function existing(): array
    {
        $db = DB::connection($this->connection);

        return match ($db->getDriverName()) {
            'mysql', 'mariadb' => array_map(fn (object $row): string => (string) $row->name, $db->select("SELECT TRIGGER_NAME AS name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'audit_logs'")),
            'sqlite' => array_map(fn (object $row): string => (string) $row->name, $db->select("SELECT name FROM sqlite_master WHERE type = 'trigger' AND tbl_name = 'audit_logs'")),
            default => [],
        };
    }
}
