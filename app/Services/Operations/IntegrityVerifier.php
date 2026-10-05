<?php

namespace App\Services\Operations;

use App\Services\Tenancy\TenancyVerifier;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SaaS-7 (C12): read-only validation of a database after a restore (or any repair, or a migration
 * rehearsal): the checks the rehearsals ran by hand, as one command (ops:verify-integrity).
 *
 * - foreign keys: rows whose reference points at no parent, for every declared foreign key (a
 *   restore taken table by table, or loaded with foreign_key_checks off, can leave them);
 * - tenancy: every TenancyVerifier check (tenant ids, cross-tenant references and morphs, roles);
 * - schema: migrations on disk that have not run;
 * - audit: whether the append-only triggers are installed.
 *
 * Counts only, never row contents. Platform-level by nature (reads every tenant's counts).
 */
class IntegrityVerifier
{
    public function __construct(
        private readonly TenancyVerifier $tenancy,
        private readonly AuditProtection $audit,
    ) {}

    /**
     * @return array{ok: bool, foreign_keys: array{checked: int, violations: array<string, int>}, tenancy: array{checks: int, violations: array<string, int>}, pending_migrations: list<string>, audit_protected: bool}
     */
    public function report(): array
    {
        $foreignKeys = $this->foreignKeys();
        $tenancy = $this->tenancy->report();
        $tenancyViolations = array_filter($tenancy, fn (int $count): bool => $count > 0);
        $pending = $this->pendingMigrations();

        try {
            $auditProtected = $this->audit->installed();
        } catch (Throwable) {
            $auditProtected = false;
        }

        return [
            'ok' => $foreignKeys['violations'] === [] && $tenancyViolations === [] && $pending === [],
            'foreign_keys' => $foreignKeys,
            'tenancy' => ['checks' => count($tenancy), 'violations' => $tenancyViolations],
            'pending_migrations' => $pending,
            'audit_protected' => $auditProtected,
        ];
    }

    /**
     * @return array{checked: int, violations: array<string, int>}
     */
    private function foreignKeys(): array
    {
        $db = DB::connection();

        if ($db->getDriverName() === 'sqlite') {
            $rows = $db->select('PRAGMA foreign_key_check');
            $violations = [];

            foreach ($rows as $row) {
                $key = $row->table.' → '.$row->parent;
                $violations[$key] = ($violations[$key] ?? 0) + 1;
            }

            return ['checked' => count($db->select("SELECT name FROM sqlite_master WHERE type = 'table'")), 'violations' => $violations];
        }

        $keys = collect($db->select('SELECT CONSTRAINT_NAME AS name, TABLE_NAME AS child, COLUMN_NAME AS col, REFERENCED_TABLE_NAME AS parent, REFERENCED_COLUMN_NAME AS pcol FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION'))
            ->groupBy(fn (object $key): string => $key->child.'.'.$key->name);
        $violations = [];

        foreach ($keys as $name => $columns) {
            $first = $columns->first();
            $notNull = $columns->map(fn (object $c): string => "c.`{$c->col}` IS NOT NULL")->implode(' AND ');
            $join = $columns->map(fn (object $c): string => "p.`{$c->pcol}` = c.`{$c->col}`")->implode(' AND ');
            $count = (int) $db->selectOne("SELECT COUNT(*) AS n FROM `{$first->child}` c WHERE {$notNull} AND NOT EXISTS (SELECT 1 FROM `{$first->parent}` p WHERE {$join})")->n;

            if ($count > 0) {
                $violations[(string) $name] = $count;
            }
        }

        return ['checked' => $keys->count(), 'violations' => $violations];
    }

    /**
     * @return list<string>
     */
    private function pendingMigrations(): array
    {
        $migrator = app('migrator');

        if (! $migrator->repositoryExists()) {
            return ['(migrations table missing)'];
        }

        $files = array_keys($migrator->getMigrationFiles(database_path('migrations')));
        $ran = $migrator->getRepository()->getRan();

        return array_values(array_diff($files, $ran));
    }
}
